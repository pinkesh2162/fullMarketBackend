<?php

namespace App\Services\FirebaseMigration;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Rating;
use App\Models\SearchSuggestion;
use App\Models\Store;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class FirestoreMigrationService
{
    public function __construct(
        protected MigrationState $state,
        protected MigrationLogger $logger,
        protected FirestoreExportReader $reader,
        protected CategoryReferenceResolver $categoryResolver,
        protected MediaImportHelper $mediaHelper
    ) {}

    protected function disk(): string
    {
        return (string) config('firebase-migration.media_disk', 'public');
    }

    protected function passwordHash(): string
    {
        return Hash::make('Test@1234');
    }

    /**
     * Periodic progress for long CLI steps (avoids “silent hang” while scanning many JSON files).
     * Uses a tighter interval for small exports (e.g. 75 files) so the next line is not ~50 files away.
     */
    protected function lineMigrationProgress(callable $line, string $label, int $current, int $total): void
    {
        if ($total <= 0) {
            return;
        }
        $configured = max(1, (int) config('firebase-migration.progress_interval', 50));
        if ($total <= 200) {
            $interval = max(1, (int) ceil($total / 15));
        } else {
            $interval = min($configured, max(1, (int) ceil($total / 20)));
        }
        if ($current === 1 || $current === $total || ($current % $interval) === 0) {
            $line(sprintf('%s: progress %d/%d file(s)', $label, $current, $total));
        }
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function run(?int $limit, bool $dryRun, bool $skipMedia, ?callable $line = null): array
    {
        $line ??= static function (): void {};

        $totals = ['ok' => 0, 'skip' => 0, 'err' => 0];

        foreach (['runUsers', 'runCategories', 'runStores', 'runListings', 'runFavorites', 'runReviews', 'runStoreFollowers', 'runSearchQueries', 'runAppConfig'] as $step) {
            $r = $this->{$step}($limit, $dryRun, $skipMedia, $line);
            $totals['ok'] += $r['ok'];
            $totals['skip'] += $r['skip'];
            $totals['err'] += $r['err'];
        }

        return $totals;
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runCategories(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folders = config('firebase-migration.category_source_folders');
        if (! is_array($folders) || $folders === []) {
            $folders = [(string) config('firebase-migration.categories_folder', 'categories')];
        }

        $docs = [];
        $foldersWithFiles = 0;
        foreach ($folders as $folder) {
            $folder = trim((string) $folder);
            if ($folder === '') {
                continue;
            }
            $paths = $this->reader->jsonFiles($folder);
            if ($paths !== []) {
                $foldersWithFiles++;
            }
            foreach ($paths as $path) {
                $doc = $this->reader->readDocument($path);
                if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                    $this->logger->skip('category', 'invalid_json', ['path' => $path, 'folder' => $folder]);

                    continue;
                }
                $fbId = (string) $doc['__id'];
                if (isset($docs[$fbId])) {
                    continue;
                }
                $docs[$fbId] = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            }
        }

        $ordered = $this->orderCategoriesForInsert($docs);
        $line(sprintf(
            'Categories: %d document(s) from %d non-empty export folder(s) [%s], applying limit=%s…',
            count($ordered),
            $foldersWithFiles,
            implode(', ', $folders),
            $limit === null ? 'none (all)' : (string) $limit
        ));
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($ordered as $fbId) {
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $d = $docs[$fbId];
            $n++;

            $mapped = $this->state->getCategoryId($fbId);
            if ($mapped !== null && Category::query()->whereKey($mapped)->exists()) {
                $skip++;

                continue;
            }
            if ($mapped !== null && ! Category::query()->whereKey($mapped)->exists()) {
                $this->state->forgetCategory($fbId);
            }

            $name = $this->categoryName($d);
            if ($name === null) {
                $this->logger->skip('category', 'empty_name', ['firebase_doc' => $fbId]);
                $skip++;

                continue;
            }

            $parentFb = $this->parentFirebaseId($d);
            $parentLocal = null;
            if ($parentFb !== null) {
                $parentLocal = $this->state->getCategoryId($parentFb);
                if ($parentLocal === null && isset($docs[$parentFb])) {
                    $this->logger->skip('category', 'parent_missing', ['firebase_doc' => $fbId, 'parent' => $parentFb]);
                    $skip++;

                    continue;
                }
            }

            $ownerUid = $this->categoryFirestoreOwnerUid($d);
            $ownerLocalUserId = $this->resolveCategoryOwnerLocalUserId($ownerUid);

            $dedupKey = $this->categoryDedupKey($name, $parentLocal, $ownerLocalUserId);
            $existingDedup = $this->state->getCategoryDedupLocalId($dedupKey);
            if ($existingDedup !== null && Category::query()->whereKey($existingDedup)->exists()) {
                $this->state->setCategoryId($fbId, $existingDedup);
                if (! $dryRun) {
                    $this->state->save();
                }
                $skip++;

                continue;
            }

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $slugForDb = $this->uniqueCategorySlugForInsert(
                    FirestoreDataNormalizer::trimString($d['slug'] ?? null)
                );

                $cat = Category::query()->create([
                    'firebase_id' => $fbId,
                    'user_id' => $ownerLocalUserId,
                    'name' => $name,
                    'slug' => $slugForDb,
                    'parent_id' => $parentLocal,
                ]);
                $ts = $this->categoryTimestamps($d);
                if (($ts['created'] ?? null) || ($ts['updated'] ?? null)) {
                    DB::table('categories')->where('id', $cat->id)->update(array_filter([
                        'created_at' => $ts['created'] ?? $cat->created_at,
                        'updated_at' => $ts['updated'] ?? $ts['created'] ?? $cat->updated_at,
                    ]));
                }
                $this->state->setCategoryId($fbId, (int) $cat->id);
                $this->state->setCategoryDedup($dedupKey, (int) $cat->id);

                if (! $skipMedia) {
                    $img = $this->firstImageUrlFromCategory($d);
                    if ($img !== null) {
                        $this->mediaHelper->attachFromUrl($cat, $img, Category::CATEGORY_IMAGE, $this->disk());
                    }
                }

                $this->state->save();
                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('category', $e->getMessage(), ['firebase_doc' => $fbId]);
                $err++;
            }
        }

        $line("Categories: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @param  array<string, array<string, mixed>>  $docs
     * @return list<string>
     */
    protected function orderCategoriesForInsert(array $docs): array
    {
        $ids = array_keys($docs);
        $depth = [];
        foreach ($ids as $id) {
            $depth[$id] = $this->categoryDepth($id, $docs, []);
        }
        usort($ids, fn ($a, $b) => $depth[$a] <=> $depth[$b]);

        return $ids;
    }

    /**
     * @param  array<string, array<string, mixed>>  $docs
     * @param  list<string>  $stack
     */
    protected function categoryDepth(string $id, array $docs, array $stack): int
    {
        if (in_array($id, $stack, true)) {
            return 0;
        }
        $p = $this->parentFirebaseId($docs[$id] ?? []);
        if ($p === null || ! isset($docs[$p])) {
            return 0;
        }

        return 1 + $this->categoryDepth($p, $docs, [...$stack, $id]);
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function parentFirebaseId(array $d): ?string
    {
        foreach (['parentId', 'parentid', 'parent_id', 'parent'] as $k) {
            if (! array_key_exists($k, $d)) {
                continue;
            }
            $v = $d[$k];
            if (is_string($v) || is_numeric($v)) {
                $s = trim((string) $v);

                return $s === '' ? null : $s;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function categoryName(array $d): ?string
    {
        foreach (['name', 'title', 'label'] as $k) {
            $t = FirestoreDataNormalizer::trimString($d[$k] ?? null);
            if ($t !== null) {
                return Str::limit($t, 255, '');
            }
        }

        return null;
    }

    protected function categoryDedupKey(string $name, ?int $parentLocalId, ?int $ownerUserId = null): string
    {
        $n = strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));

        return $n.'|'.($parentLocalId ?? 0).'|'.($ownerUserId ?? 0);
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function categoryFirestoreOwnerUid(array $d): ?string
    {
        foreach ([
            'userId', 'user_id', 'ownerId', 'owner_id', 'createdBy', 'authorId', 'author_id',
            'ownerUID', 'uid', 'userUID',
        ] as $k) {
            $t = FirestoreDataNormalizer::trimString($d[$k] ?? null);
            if ($t !== null) {
                return $t;
            }
        }

        $nested = FirestoreDataNormalizer::trimString(data_get($d, 'owner.id') ?? data_get($d, 'user.id') ?? null);

        return $nested;
    }

    protected function resolveCategoryOwnerLocalUserId(?string $ownerFirebaseUid): ?int
    {
        if ($ownerFirebaseUid === null || $ownerFirebaseUid === '') {
            return null;
        }

        $mapped = $this->state->getUserId($ownerFirebaseUid);
        if ($mapped !== null) {
            return (int) $mapped;
        }

        $id = User::query()->where('firebase_id', $ownerFirebaseUid)->value('id')
            ?? User::query()->where('provider_id', $ownerFirebaseUid)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{created?: Carbon, updated?: Carbon}
     */
    protected function categoryTimestamps(array $d): array
    {
        $c = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
        $u = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);

        return [
            'created' => $c,
            'updated' => $u ?? $c,
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function firstImageUrlFromCategory(array $d): ?string
    {
        foreach (['image', 'imageUrl', 'imageURL', 'icon', 'photo'] as $k) {
            $v = $d[$k] ?? null;
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_URL)) {
                return $v;
            }
            if (is_array($v)) {
                $u = $v['url'] ?? $v['src'] ?? null;
                if (is_string($u) && filter_var($u, FILTER_VALIDATE_URL)) {
                    return $u;
                }
            }
        }
        $g = $d['gallery'] ?? $d['images'] ?? null;
        if (is_array($g)) {
            foreach ($g as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $u = $item['url'] ?? $item['src'] ?? null;
                if (is_string($u) && filter_var($u, FILTER_VALIDATE_URL)) {
                    return $u;
                }
            }
        }

        return null;
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runUsers(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.users_folder', 'users');
        $paths = $this->reader->jsonFiles($folder);
        $totalFiles = count($paths);
        $line(sprintf(
            'Users: scanning %d JSON file(s)%s — this step often dominates runtime (set FIREBASE_MIGRATION_PROGRESS_INTERVAL or use --skip-media to speed up).',
            $totalFiles,
            $limit !== null ? ' (limited run)' : ' (full import for FK mapping in sample mode)'
        ));
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $fileIndex => $path) {
            if ($totalFiles <= 150) {
                $line(sprintf('Users: file %d/%d (%s)…', $fileIndex + 1, $totalFiles, basename($path)));
            } else {
                $this->lineMigrationProgress($line, 'Users', $fileIndex + 1, $totalFiles);
            }
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('user', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }
            $fbUid = (string) $doc['__id'];
            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            $mapped = $this->state->getUserId($fbUid);
            if ($mapped !== null && User::query()->whereKey($mapped)->exists()) {
                $skip++;

                continue;
            }

            $email = FirestoreDataNormalizer::normalizeEmail(
                FirestoreDataNormalizer::trimString($d['email'] ?? null)
            );
            if ($email === null) {
                $this->logger->skip('user', 'missing_email', ['firebase_uid' => $fbUid]);
                $skip++;

                continue;
            }

            [$first, $last] = $this->resolveUserNames($d, $email);
            if ($first === null || $first === '') {
                $this->logger->skip('user', 'empty_first_name', ['firebase_uid' => $fbUid, 'email' => $email]);
                $skip++;

                continue;
            }

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $payload = $this->buildUserPayload($d, $fbUid, $email);

                $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
                if ($existing !== null) {
                    $existing->mergeCasts(['password' => 'string']);
                    $existing->fill([
                        'firebase_id' => $fbUid,
                        'first_name' => $payload['first_name'],
                        'last_name' => $payload['last_name'],
                        'email' => $email,
                        'password' => $this->passwordHash(),
                        'phone' => $payload['phone'],
                        'phone_code' => $payload['phone_code'],
                        'location' => $payload['location'],
                        'description' => $payload['description'],
                        'lang' => $payload['lang'],
                        'currency' => $payload['currency'],
                        'email_verified_at' => $payload['email_verified_at'],
                        'provider' => $payload['provider'],
                        'provider_id' => $payload['provider_id'],
                        'fcm_token' => $payload['fcm_token'],
                    ]);
                    $existing->save();

                    if ($payload['created_at'] || $payload['updated_at']) {
                        DB::table('users')->where('id', $existing->id)->update(array_filter([
                            'created_at' => $payload['created_at'],
                            'updated_at' => $payload['updated_at'] ?? $payload['created_at'],
                        ]));
                    }

                    if (! $skipMedia) {
                        $photo = $this->userProfilePhotoUrl($d);
                        if ($photo !== null && $existing->getMedia(User::PROFILE)->isEmpty()) {
                            $this->mediaHelper->attachFromUrl($existing, $photo, User::PROFILE, $this->disk());
                        }
                    }

                    $this->state->setUserId($fbUid, (int) $existing->id);
                    $this->state->save();
                    $this->syncUserSettings((int) $existing->id, $d, $dryRun);
                    $ok++;

                    continue;
                }

                $user = new User;
                $user->mergeCasts(['password' => 'string']);
                $user->fill([
                    'firebase_id' => $fbUid,
                    'first_name' => $payload['first_name'],
                    'last_name' => $payload['last_name'],
                    'email' => $email,
                    'password' => $this->passwordHash(),
                    'phone' => $payload['phone'],
                    'phone_code' => $payload['phone_code'],
                    'location' => $payload['location'],
                    'description' => $payload['description'],
                    'lang' => $payload['lang'],
                    'currency' => $payload['currency'],
                    'email_verified_at' => $payload['email_verified_at'],
                    'provider' => $payload['provider'],
                    'provider_id' => $payload['provider_id'],
                    'fcm_token' => $payload['fcm_token'],
                ]);
                $user->save();

                if ($payload['created_at'] || $payload['updated_at']) {
                    DB::table('users')->where('id', $user->id)->update(array_filter([
                        'created_at' => $payload['created_at'],
                        'updated_at' => $payload['updated_at'] ?? $payload['created_at'],
                    ]));
                }

                $this->state->setUserId($fbUid, (int) $user->id);

                if (! $skipMedia) {
                    $photo = $this->userProfilePhotoUrl($d);
                    if ($photo !== null) {
                        $this->mediaHelper->attachFromUrl($user, $photo, User::PROFILE, $this->disk());
                    }
                }

                $this->syncUserSettings((int) $user->id, $d, $dryRun);
                $this->state->save();
                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('user', $e->getMessage(), ['firebase_uid' => $fbUid]);
                $err++;
            }
        }

        $line("Users: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{
     *   first_name:string,
     *   last_name:?string,
     *   phone:?string,
     *   phone_code:?string,
     *   location:?array<string, string>,
     *   description:?string,
     *   lang:string,
     *   currency:?string,
     *   email_verified_at:?\Illuminate\Support\Carbon,
     *   provider:string,
     *   provider_id:string,
     *   fcm_token:?string,
     *   created_at:?\Illuminate\Support\Carbon,
     *   updated_at:?\Illuminate\Support\Carbon
     * }
     */
    protected function buildUserPayload(array $d, string $firebaseUid, string $email): array
    {
        [$first, $last] = $this->resolveUserNames($d, $email);
        $prefs = is_array($d['preferences'] ?? null) ? $d['preferences'] : [];
        $phone = FirestoreDataNormalizer::trimString($d['phonenumber'] ?? $d['phone'] ?? $d['phoneNumber'] ?? $d['phone_national'] ?? null);
        $phoneCode = FirestoreDataNormalizer::trimString($d['phone_country_code'] ?? $d['phoneCode'] ?? null);
        $location = $this->userLocationStoragePayload($d);
        $description = FirestoreDataNormalizer::truncateUtf8(
            FirestoreDataNormalizer::trimString($d['description'] ?? $d['bio'] ?? null)
        );
        $lang = FirestoreDataNormalizer::trimString($prefs['language'] ?? $d['lang'] ?? null) ?? 'en';
        $currency = FirestoreDataNormalizer::trimString($prefs['currency'] ?? null);
        $hasEmailVerifiedFlag = array_key_exists('email_verified', $d) || array_key_exists('emailVerified', $d);
        $verified = FirestoreDataNormalizer::truthy($d['email_verified'] ?? $d['emailVerified'] ?? null);
        $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
        $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);
        $emailVerifiedAt = $hasEmailVerifiedFlag
            ? ($verified ? ($created ?? now()) : null)
            : now();
        [$provider, $providerId] = $this->providerFromFirebaseUser($firebaseUid, $d);

        return [
            'first_name' => Str::limit((string) $first, 255, ''),
            'last_name' => $last !== null ? Str::limit($last, 255, '') : null,
            'phone' => $phone !== null ? Str::limit($phone, 255, '') : null,
            'phone_code' => $phoneCode !== null ? Str::limit($phoneCode, 32, '') : null,
            'location' => $location,
            'description' => $description,
            'lang' => Str::limit($lang, 16, ''),
            'currency' => $currency !== null ? Str::limit($currency, 16, '') : null,
            'email_verified_at' => $emailVerifiedAt,
            'provider' => $provider,
            'provider_id' => $providerId,
            'fcm_token' => FirestoreDataNormalizer::trimString($d['fcmtoken'] ?? $d['fcmToken'] ?? null),
            'created_at' => $created,
            'updated_at' => $updated ?? $created,
        ];
    }

    /**
     * Stored on users as JSON object: address, lat, long (string coordinates).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, string>|null
     */
    protected function userLocationStoragePayload(array $d): ?array
    {
        $raw = $this->userLocationPayload($d);

        return $this->formatUserLocationForDatabase($raw);
    }

    /**
     * @param  array<string, mixed>|null  $raw  From {@see normalizedLocationPayload()} (lat/long/address/place_id)
     * @return array<string, string>|null
     */
    protected function formatUserLocationForDatabase(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];

        $address = isset($raw['address']) && is_string($raw['address']) ? trim($raw['address']) : null;
        if ($address !== null && $address !== '') {
            $out['address'] = Str::limit($address, 500, '');
        }

        foreach (['lat', 'long'] as $key) {
            if (! array_key_exists($key, $raw) || $raw[$key] === null) {
                continue;
            }
            $v = $raw[$key];
            if (is_string($v) && trim($v) === '') {
                continue;
            }
            $s = $this->locationCoordinateToString($v);
            if ($s !== '') {
                $out[$key] = $s;
            }
        }

        return $out === [] ? null : $out;
    }

    protected function locationCoordinateToString(int|float|string $v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_int($v)) {
            return (string) $v;
        }

        $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>|null
     */
    protected function userLocationPayload(array $d): ?array
    {
        if (isset($d['location']) && is_string($d['location'])) {
            $t = trim($d['location']);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $decoded = json_decode($t, true);
                if (is_array($decoded)) {
                    $d['location'] = FirestoreDataNormalizer::utf8Recursive($decoded);
                }
            }
        }

        $fromLocations = $this->userLocationFromLocations($d['locations'] ?? null);
        if ($fromLocations !== null) {
            return $fromLocations;
        }

        return $this->normalizedLocationPayload(
            $d,
            ['address', 'location.address', 'location.name'],
            ['lastKnownLatitude', 'latitude', 'location.lat', 'location.latitude'],
            ['lastKnownLongitude', 'longitude', 'location.long', 'location.lng', 'location.longitude'],
            ['place_id', 'location.place_id', 'location.id']
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function providerFromFirebaseUser(string $firebaseUid, array $data): array
    {
        $uid = trim($firebaseUid);
        if (str_starts_with($uid, 'users/')) {
            $uid = substr($uid, 6);
        }

        $providerHint = FirestoreDataNormalizer::trimString(
            $data['provider']
            ?? $data['provider_name']
            ?? $data['authProvider']
            ?? $data['signInProvider']
            ?? null
        );

        if (str_contains($uid, '|')) {
            [$rawProvider, $providerId] = array_pad(explode('|', $uid, 2), 2, '');
            $provider = strtolower(trim($rawProvider));
            if (str_contains($provider, 'google')) {
                $provider = 'google';
            } elseif (str_contains($provider, 'apple')) {
                $provider = 'apple';
            }

            return [$provider !== '' ? $provider : 'firebase', trim($providerId) !== '' ? trim($providerId) : $uid];
        }

        if ($providerHint !== null) {
            $provider = strtolower(trim($providerHint));
            if (str_contains($provider, 'google')) {
                $provider = 'google';
            } elseif (str_contains($provider, 'apple')) {
                $provider = 'apple';
            } elseif ($provider === 'password') {
                $provider = 'firebase';
            }

            $providerId = FirestoreDataNormalizer::trimString(
                $data['provider_id']
                ?? $data['providerId']
                ?? $data['oauth_id']
                ?? null
            );

            return [$provider !== '' ? $provider : 'firebase', $providerId !== null ? $providerId : $uid];
        }

        return ['firebase', $uid];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{0:?string,1:?string}
     */
    protected function resolveUserNames(array $d, string $email): array
    {
        $first = FirestoreDataNormalizer::trimString($d['firstname'] ?? $d['first_name'] ?? $d['firstName'] ?? null);
        $last = FirestoreDataNormalizer::trimString($d['lastname'] ?? $d['last_name'] ?? $d['lastName'] ?? null);

        if ($first !== null) {
            return [Str::limit($first, 255, ''), $last !== null ? Str::limit($last, 255, '') : null];
        }

        $display = FirestoreDataNormalizer::trimString($d['displayName'] ?? $d['display_name'] ?? null);
        if ($display !== null) {
            $parts = preg_split('/\s+/u', $display) ?: [];
            $f = isset($parts[0]) ? trim((string) $parts[0]) : '';
            $l = count($parts) > 1 ? trim(implode(' ', array_slice($parts, 1))) : '';
            if ($f !== '') {
                return [Str::limit($f, 255, ''), $l !== '' ? Str::limit($l, 255, '') : null];
            }
        }

        $username = FirestoreDataNormalizer::trimString($d['username'] ?? null);
        if ($username !== null) {
            return [Str::limit($username, 255, ''), null];
        }

        $local = explode('@', $email)[0] ?? '';
        $local = trim($local);

        return [$local !== '' ? Str::limit($local, 255, '') : null, null];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function userProfilePhotoUrl(array $d): ?string
    {
        foreach ([
            'profilePhoto',
            'profile_photo',
            'photoURL',
            'photoUrl',
            'pp_thumb_url',
            'avatar',
            'avatarUrl',
            'ownerAvatar',
            'image',
        ] as $k) {
            $v = $d[$k] ?? null;
            if (is_string($v)) {
                $s = trim($v);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return null;
    }

    protected function userLocationFromLocations(mixed $locations): ?array
    {
        if (! is_array($locations) || $locations === []) {
            return null;
        }
        $first = $locations[0] ?? null;
        if (! is_array($first)) {
            return null;
        }

        return $this->normalizedLocationPayload(
            $first,
            ['description', 'name', 'address'],
            ['latitude', 'lat'],
            ['longitude', 'long', 'lng'],
            ['place_id', 'id']
        );
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function syncUserSettings(int $userId, array $d, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $prefs = is_array($d['preferences'] ?? null) ? $d['preferences'] : [];
        $n = is_array($d['notifications'] ?? null) ? $d['notifications'] : [];

        $hide = FirestoreDataNormalizer::truthy($prefs['hideAds'] ?? $prefs['hide_ads'] ?? false);
        $post = FirestoreDataNormalizer::truthy($prefs['notification_post'] ?? $n['post'] ?? true);
        $msg = FirestoreDataNormalizer::truthy($prefs['message_notification'] ?? $n['message'] ?? true);
        $biz = FirestoreDataNormalizer::truthy($prefs['business_create'] ?? true);
        $follow = FirestoreDataNormalizer::truthy($prefs['follow_request'] ?? true);

        $tStart = $this->parseTime($prefs['notification_time_start'] ?? null);
        $tEnd = $this->parseTime($prefs['notification_time_end'] ?? null);

        $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
        $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);

        $base = [
            'hide_ads' => $hide,
            'notification_post' => $post,
            'message_notification' => $msg,
            'business_create' => $biz,
            'follow_request' => $follow,
            'notification_time_start' => $tStart,
            'notification_time_end' => $tEnd,
        ];

        $now = $updated ?? $created ?? now();
        if (UserSetting::query()->where('user_id', $userId)->exists()) {
            DB::table('user_settings')->where('user_id', $userId)->update(array_merge($base, [
                'updated_at' => $now,
            ]));
        } else {
            DB::table('user_settings')->insert(array_merge($base, [
                'user_id' => $userId,
                'created_at' => $created ?? $now,
                'updated_at' => $now,
            ]));
        }
    }

    protected function parseTime(mixed $v): ?string
    {
        $s = FirestoreDataNormalizer::trimString($v);
        if ($s === null) {
            return null;
        }
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $s)) {
            return strlen($s) === 5 ? $s.':00' : $s;
        }

        return null;
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runStores(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.stores_folder', 'stores');
        $paths = $this->reader->jsonFiles($folder);
        $totalFiles = count($paths);
        $line(sprintf(
            'Stores: scanning up to %s of %d store JSON file(s)…',
            $limit === null ? 'all' : (string) $limit,
            $totalFiles
        ));
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $fileIndex => $path) {
            $this->lineMigrationProgress($line, 'Stores', $fileIndex + 1, $totalFiles);
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('store', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }
            $fbDocId = (string) $doc['__id'];
            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            $ownerUid = $this->storeOwnerUid($d);
            if ($ownerUid === null) {
                $this->logger->skip('store', 'missing_owner', ['firebase_doc' => $fbDocId]);
                $skip++;

                continue;
            }

            $userId = $this->state->getUserId($ownerUid);
            if ($userId === null) {
                $userId = User::query()->where('provider_id', $ownerUid)->value('id');
            }
            if ($userId === null) {
                $this->logger->skip('store', 'user_not_found', ['firebase_doc' => $fbDocId, 'owner' => $ownerUid]);
                $skip++;

                continue;
            }
            $userId = (int) $userId;

            $mapped = $this->state->getStoreIdByDocId($fbDocId);
            if ($mapped !== null && Store::query()->whereKey($mapped)->exists()) {
                $skip++;

                continue;
            }

            $name = FirestoreDataNormalizer::trimString($d['name'] ?? $d['title'] ?? null);
            if ($name === null) {
                $this->logger->skip('store', 'empty_name', ['firebase_doc' => $fbDocId]);
                $skip++;

                continue;
            }

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $existing = Store::query()->where('user_id', $userId)->first();
                if ($existing !== null) {
                    $this->state->setStoreIdByDocId($fbDocId, (int) $existing->id);
                    $this->state->setStoreIdByOwnerUid($ownerUid, (int) $existing->id);
                    $this->state->save();
                    $skip++;

                    continue;
                }

                $loc = $this->storeLocationStructured($d['location'] ?? null);
                $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
                $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);

                $store = Store::query()->create([
                    'firebase_id' => $fbDocId,
                    'user_id' => $userId,
                    'name' => Str::limit($name, 255, ''),
                    'location' => $loc,
                    'business_time' => $this->openingHours($d),
                    'contact_information' => FirestoreDataNormalizer::jsonOrNull($this->storeContact($d)),
                    'social_media' => FirestoreDataNormalizer::jsonOrNull($this->storeSocial($d)),
                ]);

                if ($created || $updated) {
                    DB::table('stores')->where('id', $store->id)->update(array_filter([
                        'created_at' => $created,
                        'updated_at' => $updated ?? $created,
                    ]));
                }

                $this->state->setStoreIdByDocId($fbDocId, (int) $store->id);
                $this->state->setStoreIdByOwnerUid($ownerUid, (int) $store->id);

                if (! $skipMedia) {
                    $banner = FirestoreDataNormalizer::trimString($d['banner'] ?? $d['storeBanner'] ?? null);
                    if ($banner !== null) {
                        $this->mediaHelper->attachFromUrl($store, $banner, Store::COVER_PHOTO, $this->disk());
                    }
                    $logo = FirestoreDataNormalizer::trimString($d['logo'] ?? $d['storeLogo'] ?? null);
                    if ($logo !== null) {
                        $this->mediaHelper->attachFromUrl($store, $logo, Store::PROFILE_PHOTO, $this->disk());
                    }
                }

                $this->state->save();
                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('store', $e->getMessage(), ['firebase_doc' => $fbDocId]);
                $err++;
            }
        }

        $line("Stores: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function storeOwnerUid(array $d): ?string
    {
        foreach (['userId', 'user_id', 'ownerId', 'owner_id', 'uid', 'userUID'] as $k) {
            $v = $d[$k] ?? null;
            if (is_string($v) || is_numeric($v)) {
                $s = trim((string) $v);

                return $s === '' ? null : $s;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function storeLocationStructured(mixed $loc): ?array
    {
        if ($loc === null) {
            return null;
        }
        if (is_string($loc)) {
            $t = trim($loc);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $decoded = json_decode($t, true);
                if (is_array($decoded)) {
                    $loc = FirestoreDataNormalizer::utf8Recursive($decoded);
                }
            } else {
                return ['name' => Str::limit($t, 500, '')];
            }
        }
        if (! is_array($loc)) {
            return null;
        }

        return $this->normalizedLocationPayload(
            $loc,
            ['address', 'name', 'description'],
            ['lat', 'latitude'],
            ['long', 'lng', 'longitude'],
            ['place_id', 'id']
        );
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>|null
     */
    protected function storeContact(array $d): ?array
    {
        $c = $d['contact'] ?? $d['contactInformation'] ?? [];
        if (! is_array($c)) {
            $c = [];
        }

        $email = FirestoreDataNormalizer::normalizeEmail(
            FirestoreDataNormalizer::trimString($c['email'] ?? $d['email'] ?? null)
        );
        $phone = FirestoreDataNormalizer::trimString($c['phone'] ?? $c['phone_national'] ?? $d['phone'] ?? null);
        $phoneCode = FirestoreDataNormalizer::trimString($c['phone_country_code'] ?? $d['phone_country_code'] ?? null);
        $whatsappPhone = FirestoreDataNormalizer::trimString($c['whatsapp'] ?? $c['whatsapp_number'] ?? $d['whatsapp'] ?? null);
        $whatsappCode = FirestoreDataNormalizer::trimString($c['whatsapp_country_code'] ?? $d['whatsapp_country_code'] ?? null);

        $out = [
            'email' => $email,
            'phone' => $phone,
            'phone_code' => $phoneCode,
            'whatsapp_code' => $whatsappCode,
            'whatsapp_phone' => $whatsappPhone,
        ];

        return array_filter($out, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>|null
     */
    protected function storeSocial(array $d): ?array
    {
        $s = $d['sociallinks'] ?? $d['socialLinks'] ?? $d['social_profiles'] ?? [];
        if (! is_array($s)) {
            return null;
        }

        $urls = [];
        foreach (['website', 'instagram', 'facebook', 'twitter', 'linkedin', 'youtube', 'tiktok', 'pinterest'] as $key) {
            $value = FirestoreDataNormalizer::trimString($s[$key] ?? ($key === 'website' ? ($d['website'] ?? null) : null));
            if ($value === null) {
                continue;
            }
            $urls[] = $value;
        }

        $unique = array_values(array_unique($urls));

        return $unique === [] ? null : $unique;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function openingHours(array $d): ?array
    {
        $raw = $this->extractStoreHoursSource($d);
        if ($raw === null) {
            return null;
        }

        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        $type = FirestoreDataNormalizer::trimString($raw['type'] ?? null);
        $isFlexible = strtolower((string) $type) === 'always' ? '0' : '1';

        $hours = $raw['hours'] ?? $raw['businessHours'] ?? $raw['working_hour'] ?? $raw;
        if (! is_array($hours)) {
            $hours = [];
        }

        $workingHour = [];
        foreach ($days as $day) {
            $item = $hours[$day] ?? null;
            if (! is_array($item)) {
                $item = [];
            }

            $closed = FirestoreDataNormalizer::truthy($item['closed'] ?? false);
            $open = FirestoreDataNormalizer::trimString($item['open'] ?? $item['start'] ?? $item['start_time'] ?? null);
            $close = FirestoreDataNormalizer::trimString($item['close'] ?? $item['end'] ?? $item['end_time'] ?? null);

            $workingHour[] = [
                'day' => $day,
                'is_open' => $closed ? '0' : '1',
                'end_time' => $close ?? '',
                'start_time' => $open ?? '',
            ];
        }

        return [
            'is_flexible' => $isFlexible,
            'working_hour' => $workingHour,
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>|null
     */
    protected function extractStoreHoursSource(array $d): ?array
    {
        $candidates = [
            $d['opening_hours'] ?? null,
            $d['openingHours'] ?? null,
            $d['settings']['businessHours'] ?? null,
            $d['businessHours'] ?? null,
            (is_array($d['custom_fields'] ?? null) ? ($d['custom_fields']['opening_hours'] ?? null) : null),
            (is_array($d['customFields'] ?? null) ? ($d['customFields']['opening_hours'] ?? null) : null),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runListings(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.listings_folder', 'listings');
        $paths = $this->reader->jsonFiles($folder);
        $totalFiles = count($paths);
        $line(sprintf(
            'Listings: scanning up to %s of %d listing JSON file(s)…',
            $limit === null ? 'all' : (string) $limit,
            $totalFiles
        ));
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $fileIndex => $path) {
            $this->lineMigrationProgress($line, 'Listings', $fileIndex + 1, $totalFiles);
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('listing', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }
            $fbId = (string) $doc['__id'];
            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            $mapped = $this->state->getListingId($fbId);
            if ($mapped !== null) {
                $listing = Listing::withTrashed()->find($mapped);
                $mappedFirebaseId = $listing?->firebase_id !== null ? trim((string) $listing->firebase_id) : null;
                if ($listing !== null && $mappedFirebaseId === $fbId) {
                    if ($listing->trashed()) {
                        $listing->restore();
                    }
                    $skip++;

                    continue;
                }

                // Stale mapping: firebase listing id points to a different local row.
                $this->state->forgetListing($fbId);
            }

            $ownerUid = FirestoreDataNormalizer::trimString($d['ownerId'] ?? $d['owner_id'] ?? null);
            if ($ownerUid === null) {
                $this->logger->skip('listing', 'missing_ownerId', ['firebase_doc' => $fbId]);
                $skip++;

                continue;
            }

            $userId = $this->state->getUserId($ownerUid);
            if ($userId === null) {
                $userId = User::query()->where('provider_id', $ownerUid)->value('id');
            }
            if ($userId === null) {
                $this->logger->skip('listing', 'user_not_found', ['firebase_doc' => $fbId, 'owner' => $ownerUid]);
                $skip++;

                continue;
            }
            $userId = (int) $userId;

            $storeId = $this->resolveListingStoreId($d, $ownerUid, $userId);
            if ($storeId !== null && ! Store::query()->whereKey($storeId)->exists()) {
                $this->logger->skip('listing', 'invalid_store_fk', ['firebase_doc' => $fbId, 'store_id' => $storeId]);
                $storeId = null;
            }
            if ($storeId === null) {
                // Store is optional in our schema; keep listing import even if owner store is missing.
                $this->logger->skip('listing', 'store_not_found', ['firebase_doc' => $fbId, 'owner' => $ownerUid]);
            }

            $categoryId = $this->categoryResolver->resolveLocalCategoryId($d);
            if ($categoryId === null) {
                $categoryId = $this->resolveListingCategoryFromExportFields($d);
            }
            $listingCustomCategoryToken = $this->listingMainServiceCategoryOrCategoryIdToken($d);
            if ($categoryId === null && $listingCustomCategoryToken !== null && str_starts_with($listingCustomCategoryToken, 'custom_service')) {
                // Resolve user custom categories before global name match so we never attach a global row by accident.
                $categoryId = $this->resolveOrCreateListingCategoryFromExportPayload($d, $userId, $dryRun);
            } elseif ($categoryId === null) {
                $categoryId = $this->resolveCategoryFromListingName($d, $dryRun);
            }
            if ($categoryId === null) {
                $categoryId = $this->resolveOrCreateListingCategoryFromExportPayload($d, $userId, $dryRun);
            }
            if ($categoryId === null) {
                $this->logger->skip('listing', 'unresolved_category', ['firebase_doc' => $fbId]);
            }
            if ($categoryId !== null && ! Category::query()->whereKey($categoryId)->exists()) {
                $this->logger->skip('listing', 'category_missing_in_db', ['firebase_doc' => $fbId, 'category_id' => $categoryId]);
                $categoryId = null;
            }

            $title = FirestoreDataNormalizer::trimString($d['name'] ?? $d['title'] ?? null);
            if ($title === null) {
                $this->logger->skip('listing', 'empty_title', ['firebase_doc' => $fbId]);
            }

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $serviceType = $this->mapServiceType($d);
                $stats = is_array($d['stats'] ?? null) ? $d['stats'] : [];
                $views = (int) ($stats['viewCount'] ?? $stats['viewcount'] ?? 0);
                $availability = array_key_exists('availability', $d)
                    ? FirestoreDataNormalizer::truthy($d['availability'])
                    : $this->isActiveStatus($d['status'] ?? null);
                $tags = $d['tags'] ?? [];
                $search = '';
                if (is_array($tags)) {
                    $search = implode(', ', array_map(static fn ($t) => (string) $t, $tags));
                } elseif (is_string($tags)) {
                    $search = $tags;
                }
                $search = FirestoreDataNormalizer::truncateUtf8($search) ?? '';

                $listing = Listing::query()->create([
                    'firebase_id' => $fbId,
                    'user_id' => $userId,
                    'store_id' => $storeId,
                    'service_type' => $serviceType,
                    'title' => $title !== null ? Str::limit($title, 255, '') : null,
                    'views_count' => max(0, $views),
                    'service_category' => $categoryId,
                    'service_modality' => $this->serviceModality($d),
                    'description' => FirestoreDataNormalizer::truncateUtf8(FirestoreDataNormalizer::trimString($d['description'] ?? $d['content'] ?? '') ?? ''),
                    'search_keyword' => $search !== '' ? $search : null,
                    'contact_info' => FirestoreDataNormalizer::jsonOrNull($this->listingContact($d)),
                    'additional_info' => FirestoreDataNormalizer::jsonOrNull($this->listingAdditional($d, $fbId)),
                    'currency' => FirestoreDataNormalizer::trimString($d['currency'] ?? null),
                    'price' => $this->listingPrice($d),
                    'availability' => $availability,
                    'condition' => FirestoreDataNormalizer::trimString($d['condition'] ?? null),
                    'listing_type' => $this->listingTypeFlags($d, $serviceType),
                    'property_type' => $this->propertyType($d, $serviceType),
                    'bedrooms' => $this->strOrNull($d['bedrooms'] ?? data_get($d, 'custom_fields.bedrooms') ?? data_get($d, 'customFields.bedrooms')),
                    'bathrooms' => $this->strOrNull($d['bathrooms'] ?? data_get($d, 'custom_fields.bathrooms') ?? data_get($d, 'customFields.bathrooms')),
                    'advance_options' => $this->propertyAdvance($d, $serviceType),
                    'vehicle_type' => $this->vehicleType($d, $serviceType),
                    'vehical_info' => $this->vehicleInfoJson($d, $serviceType),
                    'fual_type' => $this->fuelType($d, $serviceType),
                    'transmission' => FirestoreDataNormalizer::trimString($d['transmission'] ?? null),
                ]);

                $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
                $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);
                $deleted = FirestoreDataNormalizer::parseTimestamp($d['deletedAt'] ?? $d['deletedat'] ?? null);
                $effectiveCreated = $created ?? $updated;
                DB::table('listings')->where('id', $listing->id)->update([
                    'created_at' => $effectiveCreated,
                    'updated_at' => $updated,
                    'deleted_at' => $deleted,
                ]);

                $this->state->setListingId($fbId, (int) $listing->id);

                if (! $skipMedia) {
                    $urls = $this->collectListingImageUrls($d);
                    $this->mediaHelper->attachManyOrdered(
                        $listing->fresh(),
                        $urls,
                        Listing::LISTING_IMAGES,
                        $this->disk(),
                        'listing-'.$listing->id
                    );
                }

                $this->state->save();
                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('listing', $e->getMessage(), ['firebase_doc' => $fbId]);
                $err++;
            }
        }

        $line("Listings: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runFavorites(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.favorites_folder', 'favorites');
        $paths = $this->reader->jsonFiles($folder);
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }

            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('favorite', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }

            $fbId = (string) $doc['__id'];
            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            $itemType = strtolower((string) ($d['itemType'] ?? $d['item_type'] ?? ''));
            if ($itemType !== '' && $itemType !== 'listing') {
                $this->logger->skip('favorite', 'unsupported_item_type', ['firebase_doc' => $fbId, 'item_type' => $itemType]);
                $skip++;

                continue;
            }

            $fbUserId = $this->favoriteUserFirebaseId($d);
            $fbListingId = $this->favoriteListingFirebaseId($d);

            if ($fbUserId === null || $fbListingId === null) {
                $this->logger->skip('favorite', 'missing_user_or_listing_id', ['firebase_doc' => $fbId]);
                $skip++;

                continue;
            }

            $userId = $this->state->getUserId($fbUserId);
            if ($userId !== null) {
                $stateUser = User::query()->whereKey($userId)->first(['id', 'firebase_id', 'provider_id']);
                $stateFirebaseId = is_string($stateUser?->firebase_id) ? trim((string) $stateUser->firebase_id) : '';
                $stateProviderId = is_string($stateUser?->provider_id) ? trim((string) $stateUser->provider_id) : '';
                if ($stateUser === null || ($stateFirebaseId !== $fbUserId && $stateProviderId !== $fbUserId)) {
                    $userId = null;
                }
            }
            if ($userId === null) {
                $userId = User::query()->where('firebase_id', $fbUserId)->value('id')
                    ?? User::query()->where('provider_id', $fbUserId)->value('id');
                $userId = $userId !== null ? (int) $userId : null;
            }
            if ($userId === null) {
                $this->logger->skip('favorite', 'user_not_found', ['firebase_doc' => $fbId, 'firebase_user' => $fbUserId]);
                $skip++;

                continue;
            }

            $listingId = $this->state->getListingId($fbListingId);
            if ($listingId !== null) {
                $stateListing = Listing::withTrashed()->whereKey($listingId)->first(['id', 'firebase_id', 'deleted_at']);
                $stateFirebaseId = is_string($stateListing?->firebase_id) ? trim((string) $stateListing->firebase_id) : '';
                if ($stateListing === null || $stateFirebaseId !== $fbListingId) {
                    $this->state->forgetListing($fbListingId);
                    $listingId = null;
                } elseif ($stateListing->deleted_at !== null) {
                    Listing::withTrashed()->whereKey($stateListing->id)->restore();
                }
            }
            if ($listingId === null) {
                $byFirebase = Listing::withTrashed()->where('firebase_id', $fbListingId)->first(['id', 'deleted_at']);
                if ($byFirebase !== null && $byFirebase->deleted_at !== null) {
                    Listing::withTrashed()->whereKey($byFirebase->id)->restore();
                }
                $listingId = $byFirebase !== null ? (int) $byFirebase->id : null;
            }
            if ($listingId === null) {
                if ($dryRun) {
                    $this->logger->skip('favorite', 'listing_not_found', ['firebase_doc' => $fbId, 'firebase_listing' => $fbListingId]);
                    $skip++;

                    continue;
                }
                try {
                    $itemData = is_array($d['itemData'] ?? null) ? $d['itemData'] : [];
                    $ownerFirebase = FirestoreDataNormalizer::trimString(
                        $itemData['ownerId']
                        ?? $itemData['authorId']
                        ?? data_get($itemData, 'author.id')
                        ?? null
                    );
                    $ownerUserId = $userId;
                    if ($ownerFirebase !== null) {
                        $ownerMapped = $this->state->getUserId($ownerFirebase);
                        if ($ownerMapped === null) {
                            $ownerMapped = User::query()->where('firebase_id', $ownerFirebase)->value('id')
                                ?? User::query()->where('provider_id', $ownerFirebase)->value('id');
                        }
                        if ($ownerMapped !== null) {
                            $ownerUserId = (int) $ownerMapped;
                        }
                    }
                    $title = FirestoreDataNormalizer::trimString($itemData['title'] ?? null) ?? 'Imported from favorite';
                    $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
                    $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);

                    $listing = Listing::query()->create([
                        'firebase_id' => $fbListingId,
                        'user_id' => $ownerUserId,
                        'store_id' => null,
                        'service_type' => Listing::ARTICLE_FOR_SALE,
                        'title' => Str::limit($title, 255, ''),
                        'availability' => true,
                    ]);
                    DB::table('listings')->where('id', $listing->id)->update([
                        'created_at' => $created,
                        'updated_at' => $updated,
                    ]);
                    $listingId = (int) $listing->id;
                    $this->state->setListingId($fbListingId, $listingId);
                    $this->state->save();
                } catch (Throwable) {
                    $this->logger->skip('favorite', 'listing_not_found', ['firebase_doc' => $fbId, 'firebase_listing' => $fbListingId]);
                    $skip++;

                    continue;
                }
            }

            $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
            $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);
            $deleted = FirestoreDataNormalizer::parseTimestamp($d['deletedAt'] ?? $d['deletedat'] ?? null);

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $favorite = Favorite::query()
                    ->where('firebase_id', $fbId)
                    ->orWhere(fn ($q) => $q->where('user_id', $userId)->where('listing_id', $listingId))
                    ->first();

                if ($favorite) {
                    $favorite->fill([
                        'firebase_id' => $fbId,
                        'user_id' => $userId,
                        'listing_id' => $listingId,
                    ]);
                    $favorite->save();
                    DB::table('favorites')->where('id', $favorite->id)->update(array_filter([
                        'updated_at' => $updated ?? $created ?? $favorite->updated_at,
                        'deleted_at' => $deleted,
                    ], fn ($v) => $v !== null));
                } else {
                    $favorite = Favorite::query()->create([
                        'firebase_id' => $fbId,
                        'user_id' => $userId,
                        'listing_id' => $listingId,
                    ]);
                    DB::table('favorites')->where('id', $favorite->id)->update(array_filter([
                        'created_at' => $created,
                        'updated_at' => $updated ?? $created,
                        'deleted_at' => $deleted,
                    ], fn ($v) => $v !== null));
                }

                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('favorite', $e->getMessage(), ['firebase_doc' => $fbId]);
                $err++;
            }
        }

        $line("Favorites: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runAppConfig(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.app_config_folder', 'app_config');
        $paths = $this->reader->jsonFiles($folder);
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }

            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('app_config', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }

            $docId = (string) $doc['__id'];
            $data = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            // We only migrate the app settings payload used by clients for version/maintenance.
            if ($docId !== 'version_and_maintenance') {
                $skip++;

                continue;
            }

            $payload = [
                'maintenance_mode' => FirestoreDataNormalizer::truthy($data['maintenance_mode'] ?? false),
                'maintenance_title' => FirestoreDataNormalizer::trimString($data['maintenance_title'] ?? null),
                'maintenance_message' => FirestoreDataNormalizer::trimString($data['maintenance_message'] ?? null),
                'min_version_android' => FirestoreDataNormalizer::trimString($data['min_version_android'] ?? null) ?? '1.0.0',
                'latest_version_android' => FirestoreDataNormalizer::trimString($data['latest_version_android'] ?? null) ?? '1.0.0',
                'android_store_url' => FirestoreDataNormalizer::trimString($data['android_store_url'] ?? null),
                'min_version_ios' => FirestoreDataNormalizer::trimString($data['min_version_ios'] ?? null) ?? '1.0.0',
                'latest_version_ios' => FirestoreDataNormalizer::trimString($data['latest_version_ios'] ?? null) ?? '1.0.0',
                'ios_store_url' => FirestoreDataNormalizer::trimString($data['ios_store_url'] ?? null),
                'force_update_below_min' => FirestoreDataNormalizer::truthy($data['force_update_below_min'] ?? false),
                'release_notes' => FirestoreDataNormalizer::trimString($data['release_notes'] ?? null),
                'enabled_location_filter' => FirestoreDataNormalizer::truthy($data['enabled_location_filter'] ?? false),
            ];

            if ($dryRun) {
                $ok++;
                continue;
            }

            try {
                $row = AppSetting::query()->orderBy('id')->first();
                if ($row === null) {
                    AppSetting::query()->create($payload);
                } else {
                    $row->fill($payload);
                    $row->save();
                }
                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('app_config', $e->getMessage(), ['firebase_doc' => $docId]);
                $err++;
            }
        }

        $line("App config: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runSearchQueries(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.search_queries_folder', 'search_queries');
        $paths = $this->reader->jsonFiles($folder);
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }

            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('search_query', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }

            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            $term = FirestoreDataNormalizer::trimString($d['query'] ?? null)
                ?? FirestoreDataNormalizer::trimString($d['queryLower'] ?? null);
            if ($term === null) {
                $this->logger->skip('search_query', 'missing_query', ['firebase_doc' => (string) ($doc['__id'] ?? '')]);
                $skip++;

                continue;
            }

            $hits = max(1, (int) ($d['count'] ?? 1));

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $existing = SearchSuggestion::query()->where('term', $term)->first();
                if ($existing === null) {
                    SearchSuggestion::query()->create([
                        'term' => Str::limit($term, 255, ''),
                        'hits' => $hits,
                    ]);
                } else {
                    $existing->hits = max((int) $existing->hits, $hits);
                    $existing->save();
                }
                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('search_query', $e->getMessage(), ['firebase_doc' => (string) ($doc['__id'] ?? '')]);
                $err++;
            }
        }

        $line("Search queries: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runStoreFollowers(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.store_followers_folder', 'store_followers');
        $paths = $this->reader->jsonFiles($folder);
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }

            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('store_follower', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }

            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $fbDocId = (string) $doc['__id'];
            $n++;

            $followerUid = FirestoreDataNormalizer::trimString($d['followerId'] ?? $d['follower_id'] ?? null);
            $ownerUid = FirestoreDataNormalizer::trimString($d['storeOwnerId'] ?? $d['store_owner_id'] ?? null);
            if ($followerUid === null || $ownerUid === null) {
                $this->logger->skip('store_follower', 'missing_user_or_owner', ['firebase_doc' => $fbDocId]);
                $skip++;

                continue;
            }

            $userId = $this->state->getUserId($followerUid);
            if ($userId === null) {
                $userId = User::query()->where('firebase_id', $followerUid)->value('id');
            }
            if ($userId === null) {
                $userId = User::query()->where('provider_id', $followerUid)->value('id');
            }
            if ($userId === null) {
                $this->logger->skip('store_follower', 'follower_not_found', ['firebase_doc' => $fbDocId, 'follower' => $followerUid]);
                $skip++;

                continue;
            }
            $userId = (int) $userId;

            $storeId = $this->state->getStoreIdByOwnerUid($ownerUid);
            if ($storeId === null) {
                $ownerUserId = $this->state->getUserId($ownerUid);
                if ($ownerUserId === null) {
                    $ownerUserId = User::query()->where('firebase_id', $ownerUid)->value('id');
                }
                if ($ownerUserId === null) {
                    $ownerUserId = User::query()->where('provider_id', $ownerUid)->value('id');
                }
                if ($ownerUserId !== null) {
                    $storeId = Store::query()->where('user_id', (int) $ownerUserId)->value('id');
                }
            }
            if ($storeId === null) {
                $this->logger->skip('store_follower', 'store_not_found', ['firebase_doc' => $fbDocId, 'owner' => $ownerUid]);
                $skip++;

                continue;
            }
            $storeId = (int) $storeId;

            $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
            $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);
            $now = now();

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                DB::table('follows')->updateOrInsert(
                    ['user_id' => $userId, 'store_id' => $storeId],
                    [
                        'created_at' => $created ?? $now,
                        'updated_at' => $updated ?? $created ?? $now,
                    ]
                );
                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('store_follower', $e->getMessage(), ['firebase_doc' => $fbDocId]);
                $err++;
            }
        }

        $line("Store followers: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function runReviews(?int $limit, bool $dryRun, bool $skipMedia, callable $line): array
    {
        $folder = (string) config('firebase-migration.reviews_folder', 'reviews');
        $paths = $this->reader->jsonFiles($folder);
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($paths as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }

            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('review', 'invalid_json', ['path' => $path]);
                $err++;

                continue;
            }

            $fbId = (string) $doc['__id'];
            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            $reviewerUid = FirestoreDataNormalizer::trimString($d['reviewerId'] ?? $d['reviewer_id'] ?? null);
            if ($reviewerUid === null) {
                $this->logger->skip('review', 'missing_reviewer', ['firebase_doc' => $fbId]);
                $skip++;

                continue;
            }

            $userId = $this->state->getUserId($reviewerUid);
            if ($userId === null) {
                $userId = User::query()->where('firebase_id', $reviewerUid)->value('id');
            }
            if ($userId === null) {
                $userId = User::query()->where('provider_id', $reviewerUid)->value('id');
            }
            if ($userId === null) {
                $this->logger->skip('review', 'user_not_found', ['firebase_doc' => $fbId, 'firebase_user' => $reviewerUid]);
                $skip++;

                continue;
            }
            $userId = (int) $userId;

            $targetType = strtolower((string) ($d['targetType'] ?? $d['target_type'] ?? ''));
            $targetId = FirestoreDataNormalizer::trimString($d['targetId'] ?? $d['target_id'] ?? null);
            if ($targetId === null) {
                $this->logger->skip('review', 'missing_target', ['firebase_doc' => $fbId]);
                $skip++;

                continue;
            }

            $storeId = null;
            if ($targetType === 'store') {
                $storeId = $this->state->getStoreIdByDocId($targetId);
                if ($storeId === null) {
                    $storeId = Store::query()->where('firebase_id', $targetId)->value('id');
                }
            } else {
                $listingId = $this->state->getListingId($targetId);
                if ($listingId === null) {
                    $listingId = Listing::query()->where('firebase_id', $targetId)->value('id');
                }
                if ($listingId !== null) {
                    $storeId = Listing::query()->whereKey((int) $listingId)->value('store_id');
                }
            }

            if ($storeId === null) {
                $this->logger->skip('review', 'store_not_found', [
                    'firebase_doc' => $fbId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                ]);
                $skip++;

                continue;
            }

            $ratingValue = (int) ($d['rating'] ?? 0);
            $ratingValue = max(1, min(5, $ratingValue));
            $content = FirestoreDataNormalizer::trimString($d['content'] ?? null);
            $title = FirestoreDataNormalizer::trimString($d['title'] ?? null);
            $comment = $content;
            if ($title !== null && $title !== '') {
                $comment = $comment !== null && $comment !== '' ? ($title."\n".$comment) : $title;
            }
            $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
            $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $rating = Rating::query()
                    ->where('user_id', $userId)
                    ->where('store_id', (int) $storeId)
                    ->first();

                if ($rating === null) {
                    $rating = Rating::query()->create([
                        'user_id' => $userId,
                        'store_id' => (int) $storeId,
                        'rating' => $ratingValue,
                        'comment' => $comment,
                    ]);
                } else {
                    $rating->fill([
                        'rating' => $ratingValue,
                        'comment' => $comment,
                    ]);
                    $rating->save();
                }

                DB::table('ratings')->where('id', $rating->id)->update(array_filter([
                    'created_at' => $created ?? $rating->created_at,
                    'updated_at' => $updated ?? $created ?? $rating->updated_at,
                ], fn ($v) => $v !== null));

                $ok++;
            } catch (Throwable $e) {
                $this->logger->error('review', $e->getMessage(), ['firebase_doc' => $fbId]);
                $err++;
            }
        }

        $line("Reviews: ok={$ok} skip={$skip} err={$err}");

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function resolveListingStoreId(array $d, string $ownerUid, int $userId): ?int
    {
        $candidateStoreKeys = [
            $d['author_id'] ?? null,
            $d['authorId'] ?? null,
            $d['storeId'] ?? null,
            $d['store_id'] ?? null,
        ];

        foreach ($candidateStoreKeys as $keyRaw) {
            if (! is_string($keyRaw) && ! is_numeric($keyRaw)) {
                continue;
            }
            $key = trim((string) $keyRaw);
            if ($key === '') {
                continue;
            }

            $byDoc = $this->state->getStoreIdByDocId($key);
            if ($byDoc !== null) {
                return $byDoc;
            }
            $byOwner = $this->state->getStoreIdByOwnerUid($key);
            if ($byOwner !== null) {
                return $byOwner;
            }
        }

        $byOwnerUid = $this->state->getStoreIdByOwnerUid($ownerUid);
        if ($byOwnerUid !== null) {
            return $byOwnerUid;
        }

        $store = Store::query()->where('user_id', $userId)->value('id');

        return $store !== null ? (int) $store : null;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function favoriteUserFirebaseId(array $d): ?string
    {
        $candidates = [
            $d['author'] ?? null,
            $d['userId'] ?? null,
            $d['user_id'] ?? null,
            data_get($d, 'itemData.author.id'),
            data_get($d, 'itemData.author.uid'),
            data_get($d, 'itemData.authorId'),
            data_get($d, 'itemData.ownerId'),
        ];

        foreach ($candidates as $candidate) {
            $id = FirestoreDataNormalizer::trimString($candidate);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function favoriteListingFirebaseId(array $d): ?string
    {
        foreach ([$d['itemId'] ?? null, $d['listingId'] ?? null, $d['listing_id'] ?? null] as $candidate) {
            $id = FirestoreDataNormalizer::trimString($candidate);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function mapServiceType(array $d): string
    {
        $ad = is_string($d['ad_type'] ?? null) ? strtolower($d['ad_type']) : '';
        $lt = is_string($d['listingtype'] ?? $d['listingType'] ?? null) ? strtolower((string) ($d['listingtype'] ?? $d['listingType'])) : '';

        return match (true) {
            in_array($ad, ['property'], true) || $lt === 'property' => Listing::PROPERTY_FOR_SALE,
            in_array($ad, ['vehicle'], true) || $lt === 'vehicle' => Listing::VEHICLE_FOR_SALE,
            in_array($ad, ['service'], true) || $lt === 'service' => Listing::OFFER_SERVICE,
            default => Listing::ARTICLE_FOR_SALE,
        };
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function serviceModality(array $d): ?string
    {
        $m = $d['servicemodality'] ?? $d['serviceModality'] ?? null;
        if (is_string($m) && $m !== '') {
            return Str::limit($m, 120, '');
        }
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? null;
        if (is_array($cf)) {
            $v = $cf['service_modality'] ?? $cf['modality'] ?? null;
            if (is_string($v) && $v !== '') {
                return Str::limit($v, 120, '');
            }
        }

        return null;
    }

    protected function isActiveStatus(mixed $status): bool
    {
        $s = FirestoreDataNormalizer::trimString(is_string($status) ? $status : null);

        return $s !== null && strcasecmp($s, 'Active') === 0;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    protected function listingContact(array $d): array
    {
        $c = isset($d['contact']) && is_array($d['contact']) ? $d['contact'] : [];
        $phoneNumber = FirestoreDataNormalizer::trimString($c['phone'] ?? $c['phone_national'] ?? null);
        $phoneCode = FirestoreDataNormalizer::trimString($c['phone_country_code'] ?? null);
        $waNumber = FirestoreDataNormalizer::trimString($c['whatsapp'] ?? $c['whatsapp_number'] ?? $c['whatsapp_national'] ?? null);
        $waCode = FirestoreDataNormalizer::trimString($c['whatsapp_country_code'] ?? null);
        $contactMethod = $d['contactMethod'] ?? data_get($d, 'custom_fields.contactMethod') ?? data_get($d, 'customFields.contactMethod');

        return array_filter([
            'email' => FirestoreDataNormalizer::normalizeEmail(FirestoreDataNormalizer::trimString($c['email'] ?? null)),
            'phone1' => ($phoneCode !== null || $phoneNumber !== null) ? array_filter([
                'code' => $phoneCode,
                'number' => $phoneNumber,
            ], fn ($v) => $v !== null && $v !== '') : null,
            'phone2' => ($waCode !== null || $waNumber !== null) ? array_filter([
                'code' => $waCode,
                'number' => $waNumber,
            ], fn ($v) => $v !== null && $v !== '') : null,
            'chat_enable' => FirestoreDataNormalizer::truthy($contactMethod),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    protected function listingAdditional(array $d, string $fbDocId): array
    {
        $cf = is_array($d['custom_fields'] ?? null) ? $d['custom_fields'] : (is_array($d['customFields'] ?? null) ? $d['customFields'] : []);
        $contact = is_array($d['contact'] ?? null) ? $d['contact'] : [];
        $openingHours = is_array($cf['opening_hours'] ?? null) ? $cf['opening_hours'] : [];
        $hours = is_array($openingHours['hours'] ?? null) ? $openingHours['hours'] : (is_array($openingHours) ? $openingHours : []);

        return array_filter([
            'firebase' => ['document_id' => $fbDocId],
            'website' => $this->listingWebsite($d, $cf, $contact),
            'claim_ad' => $this->boolString(FirestoreDataNormalizer::truthy($d['allow_claim'] ?? $cf['allow_claim'] ?? false)),
            'location' => $this->listingLocation($d),
            'schedule' => $this->listingSchedule($hours),
            'social_media' => $this->listingSocialMedia($d, $cf),
            'is_working_hour' => $this->boolString($this->listingIsWorkingHour($openingHours)),
            'show_approximate_location' => $this->boolString(FirestoreDataNormalizer::truthy(
                $d['showApproximateLocation']
                ?? $d['show_approximate_location']
                ?? $cf['show_approximate_location']
                ?? false
            )),
        ], fn ($v) => $v !== null && $v !== []);
    }

    protected function boolString(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $cf
     * @param  array<string, mixed>  $contact
     */
    protected function listingWebsite(array $d, array $cf, array $contact): ?string
    {
        $candidates = [
            $d['website'] ?? null,
            $d['website_url'] ?? null,
            data_get($d, 'contact.website'),
            data_get($d, 'contactInformation.website'),
            $cf['website'] ?? null,
            $cf['website_url'] ?? null,
            data_get($cf, 'contact.website'),
            data_get($cf, 'contactInformation.website'),
            data_get($d, 'custom_fields.website'),
            data_get($d, 'custom_fields.website_url'),
            data_get($d, 'customFields.website'),
            data_get($d, 'customFields.website_url'),
            $contact['website'] ?? null,
        ];

        foreach ($candidates as $value) {
            $s = FirestoreDataNormalizer::trimString($value);
            if ($s !== null) {
                return $s;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $hours
     * @return list<array<string,string>>
     */
    protected function listingSchedule(array $hours): array
    {
        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $out = [];
        foreach ($days as $day) {
            $row = $hours[$day] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $start = FirestoreDataNormalizer::trimString($row['open'] ?? $row['start'] ?? null);
            $end = FirestoreDataNormalizer::trimString($row['close'] ?? $row['end'] ?? null);
            if ($start === null && $end === null) {
                continue;
            }
            $out[] = array_filter([
                'day' => $day,
                'start' => $start,
                'end' => $end,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $cf
     * @return array<string, string>|null
     */
    protected function listingSocialMedia(array $d, array $cf): ?array
    {
        $out = array_filter([
            'instagram' => FirestoreDataNormalizer::trimString($d['social_instagram'] ?? $cf['social_instagram'] ?? null),
            'facebook' => FirestoreDataNormalizer::trimString($d['social_facebook'] ?? $cf['social_facebook'] ?? null),
            'tiktok' => FirestoreDataNormalizer::trimString($d['social_tiktok'] ?? $cf['social_tiktok'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>  $openingHours
     */
    protected function listingIsWorkingHour(array $openingHours): bool
    {
        $type = FirestoreDataNormalizer::trimString($openingHours['type'] ?? null);
        if ($type === null) {
            return false;
        }

        return strtolower($type) !== 'always';
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function listingLocation(array $d): ?array
    {
        $loc = $d['location'] ?? null;
        if (is_string($loc)) {
            $t = trim($loc);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $decoded = json_decode($t, true);
                if (is_array($decoded)) {
                    $loc = FirestoreDataNormalizer::utf8Recursive($decoded);
                }
            }
        }
        if (is_array($loc)) {
            $fromLocation = $this->normalizedLocationPayload(
                $loc,
                ['address', 'name', 'description'],
                ['lat', 'latitude'],
                ['long', 'lng', 'longitude'],
                ['place_id', 'id']
            );
            if ($fromLocation !== null) {
                return $fromLocation;
            }
        }

        return $this->normalizedLocationPayload(
            $d,
            ['address', 'location.address', 'location.name'],
            ['latitude', 'lat', 'location.latitude', 'location.lat'],
            ['longitude', 'long', 'lng', 'location.longitude', 'location.long', 'location.lng'],
            ['place_id', 'location.place_id', 'geohash']
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $addressPaths
     * @param  list<string>  $latPaths
     * @param  list<string>  $longPaths
     * @param  list<string>  $placeIdPaths
     * @return array<string, mixed>|null
     */
    protected function normalizedLocationPayload(
        array $source,
        array $addressPaths,
        array $latPaths,
        array $longPaths,
        array $placeIdPaths = []
    ): ?array {
        $address = $this->firstNonEmptyStringByPaths($source, $addressPaths);
        $lat = $this->firstNumericOrStringByPaths($source, $latPaths);
        $long = $this->firstNumericOrStringByPaths($source, $longPaths);
        $placeId = $this->firstNonEmptyStringByPaths($source, $placeIdPaths);

        if ($address === null && $lat === null && $long === null) {
            return null;
        }

        return array_filter([
            'lat' => $lat,
            'long' => $long,
            'address' => $address !== null ? Str::limit($address, 500, '') : null,
            'place_id' => $placeId !== null ? Str::limit($placeId, 255, '') : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $paths
     */
    protected function firstNonEmptyStringByPaths(array $source, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($source, $path);
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            } elseif (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $paths
     */
    protected function firstNumericOrStringByPaths(array $source, array $paths): int|float|string|null
    {
        foreach ($paths as $path) {
            $value = data_get($source, $path);
            if (is_int($value) || is_float($value)) {
                return $value;
            }
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    if (is_numeric($trimmed)) {
                        return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
                    }

                    return $trimmed;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function listingPrice(array $d): ?string
    {
        $p = $d['price'] ?? null;
        if ($p === null || $p === '') {
            return null;
        }

        return is_string($p) ? Str::limit($p, 64, '') : (string) $p;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function listingTypeFlags(array $d, string $serviceType): ?int
    {
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
        $sr = is_array($cf) ? ($cf['sale_rent'] ?? null) : null;
        $lt = $d['listing_type'] ?? $d['listingType'] ?? null;
        if (is_string($lt) && strcasecmp($lt, 'sale') === 0) {
            return Listing::FOR_SALE;
        }
        if (is_string($lt) && strcasecmp($lt, 'rent') === 0) {
            return Listing::FOR_RENT;
        }
        if (is_string($sr) && strtolower($sr) === 'sale') {
            return Listing::FOR_SALE;
        }
        if (is_string($sr) && strtolower($sr) === 'rent') {
            return Listing::FOR_RENT;
        }

        return $serviceType === Listing::PROPERTY_FOR_SALE ? Listing::FOR_SALE : Listing::FOR_RENT;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function propertyType(array $d, string $serviceType): ?string
    {
        if ($serviceType !== Listing::PROPERTY_FOR_SALE) {
            return null;
        }
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
        $pt = $d['propertyType'] ?? $d['property_type'] ?? (is_array($cf) ? ($cf['property_type'] ?? null) : null);

        return is_string($pt) && $pt !== '' ? Str::limit($pt, 120, '') : null;
    }

    protected function strOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        return Str::limit((string) $v, 64, '');
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>|null
     */
    protected function propertyAdvance(array $d, string $serviceType): ?array
    {
        if ($serviceType !== Listing::PROPERTY_FOR_SALE) {
            return null;
        }
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
        if (! is_array($cf)) {
            return null;
        }

        return FirestoreDataNormalizer::jsonOrNull(array_filter([
            'laundry_type' => $cf['laundry'] ?? $d['laundry'] ?? null,
            'parking_type' => $cf['parking'] ?? $d['parking'] ?? null,
            'square_meters' => $cf['sq_meters'] ?? $cf['square_meters'] ?? null,
            'total_surface' => $cf['total_surface'] ?? null,
            'additional_info' => [
                'amenities' => is_array($cf['amenities'] ?? null) ? $cf['amenities'] : [],
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function vehicleType(array $d, string $serviceType): ?string
    {
        if ($serviceType !== Listing::VEHICLE_FOR_SALE) {
            return null;
        }
        $v = $d['vehicleType'] ?? $d['vehicle_type'] ?? null;

        return is_string($v) && $v !== '' ? Str::limit($v, 120, '') : null;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>|null
     */
    protected function vehicleInfoJson(array $d, string $serviceType): ?array
    {
        if ($serviceType !== Listing::VEHICLE_FOR_SALE) {
            return null;
        }
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
        if (! is_array($cf)) {
            $cf = [];
        }

        return FirestoreDataNormalizer::jsonOrNull(array_filter([
            'brand' => $cf['brand'] ?? $d['brand'] ?? null,
            'model' => $cf['model'] ?? $d['model'] ?? null,
            'year' => $cf['year'] ?? $d['year'] ?? null,
            'milage' => $cf['mileage'] ?? $d['mileage'] ?? null,
            'no_of_owners' => $cf['number_of_owners'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function fuelType(array $d, string $serviceType): ?string
    {
        if ($serviceType !== Listing::VEHICLE_FOR_SALE) {
            return null;
        }
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
        $f = $cf['fuel_type'] ?? $d['fuel_type'] ?? $d['fuelType'] ?? null;

        return is_string($f) && $f !== '' ? Str::limit($f, 120, '') : null;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return list<string>
     */
    protected function collectListingImageUrls(array $d): array
    {
        $rows = [];
        foreach (['image', 'photo', 'featuredImage', 'featured_image'] as $singleKey) {
            $single = $d[$singleKey] ?? null;
            if (is_string($single) && trim($single) !== '') {
                $rows[] = ['source' => -1, 'order' => 0, 'url' => trim($single)];
            }
        }
        foreach (['gallery', 'images'] as $sourceIndex => $key) {
            $items = $d[$key] ?? null;
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    if (is_string($item) && trim($item) !== '') {
                        $rows[] = ['source' => $sourceIndex, 'order' => 0, 'url' => trim($item)];
                    }

                    continue;
                }
                $u = $item['url'] ?? $item['src'] ?? null;
                if (! is_string($u) || trim($u) === '') {
                    $sizes = $item['sizes'] ?? null;
                    if (is_array($sizes) && isset($sizes['full']['src']) && is_string($sizes['full']['src'])) {
                        $u = $sizes['full']['src'];
                    }
                }
                if (! is_string($u) || trim($u) === '') {
                    continue;
                }
                $order = isset($item['order']) ? (int) $item['order'] : 0;
                $rows[] = ['source' => $sourceIndex, 'order' => $order, 'url' => trim($u)];
            }
        }
        usort($rows, function (array $a, array $b): int {
            if ($a['source'] !== $b['source']) {
                return $a['source'] <=> $b['source'];
            }

            return $a['order'] <=> $b['order'];
        });
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $url = $row['url'];
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = $url;
        }

        return $out;
    }

    /**
     * Match catalog slug or Firestore category id string against imported rows (slug / firebase_id columns).
     */
    protected function resolveListingCategoryFromExportFields(array $d): ?int
    {
        foreach ($this->listingCategoryReferenceCandidates($d) as $candidate) {
            if ($candidate === '' || str_starts_with($candidate, 'custom_service')) {
                continue;
            }
            if (strlen($candidate) >= 18 && preg_match('/^[A-Za-z0-9]+$/', $candidate)) {
                $byFb = Category::query()->where('firebase_id', $candidate)->value('id');
                if ($byFb !== null) {
                    return (int) $byFb;
                }
            }
            $bySlug = Category::query()->where('slug', $candidate)->value('id');
            if ($bySlug !== null) {
                return (int) $bySlug;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function listingCategoryReferenceCandidates(array $d): array
    {
        $categories = $d['categories'] ?? null;
        $firstCategorySlug = null;
        if (is_array($categories) && $categories !== []) {
            $first = $categories[0];
            if (is_string($first)) {
                $firstCategorySlug = FirestoreDataNormalizer::trimString($first);
            }
        }

        $raw = [
            $d['mainServiceCategory'] ?? null,
            $d['main_service_category'] ?? null,
            data_get($d, 'custom_fields.main_service_category'),
            data_get($d, 'customFields.main_service_category'),
            data_get($d, 'customFields.mainServiceCategory'),
            $d['serviceCategoryId'] ?? null,
            $d['service_category_id'] ?? null,
            $firstCategorySlug,
            $d['categoryId'] ?? null,
            $d['category_id'] ?? null,
        ];
        $out = [];
        foreach ($raw as $v) {
            $s = FirestoreDataNormalizer::trimString(is_string($v) ? $v : null);
            if ($s !== null && $s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * When the bundled category export is incomplete, or the listing uses custom_service_*,
     * ensure a Category row exists so the API can show the same label as live.
     */
    protected function resolveOrCreateListingCategoryFromExportPayload(array $d, int $userId, bool $dryRun): ?int
    {
        if ($dryRun) {
            return null;
        }

        $msc = $this->listingMainServiceCategoryOrCategoryIdToken($d);
        if ($msc === null || $msc === '') {
            return null;
        }

        if (str_starts_with($msc, 'custom_service')) {
            $nm = FirestoreDataNormalizer::trimString(
                data_get($d, 'custom_fields.main_service_category_name')
                ?? data_get($d, 'customFields.main_service_category_name')
                ?? $d['mainServiceCategoryDisplayName']
                ?? null
            );
            if ($nm === null || $nm === '') {
                $nm = $this->firstNonEmptyListingCategoryDisplayName($d);
            }
            if ($nm === null || $nm === '') {
                $nm = 'Custom service';
            }

            $cat = Category::query()->updateOrCreate(
                ['firebase_id' => Str::limit($msc, 255)],
                [
                    'user_id' => $userId,
                    'name' => Str::limit($nm, 255),
                    'slug' => null,
                    'parent_id' => null,
                ]
            );

            return (int) $cat->id;
        }

        $slug = $this->listingPrimaryCatalogSlugToken($d);
        if ($slug === null) {
            return null;
        }

        $existing = Category::query()->where('slug', $slug)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $display = $this->firstNonEmptyListingCategoryDisplayName($d);
        if ($display === null) {
            return null;
        }

        $cat = Category::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'firebase_id' => null,
                'user_id' => null,
                'name' => Str::limit($display, 255),
                'parent_id' => null,
            ]
        );

        return (int) $cat->id;
    }

    /**
     * Listing payload often puts the catalog slug under main_service_category, but user-created
     * services may only set top-level categoryId / category_id to custom_service_* or custom_service_temp_*.
     */
    protected function listingMainServiceCategoryOrCategoryIdToken(array $d): ?string
    {
        $direct = FirestoreDataNormalizer::trimString(
            data_get($d, 'custom_fields.main_service_category')
            ?? data_get($d, 'customFields.main_service_category')
            ?? $d['mainServiceCategory']
            ?? $d['main_service_category']
            ?? null
        );
        $customTokens = [];
        if ($direct !== null && $direct !== '') {
            $customTokens[] = $direct;
        }
        foreach ($this->listingCategoryReferenceCandidates($d) as $c) {
            if (str_starts_with($c, 'custom_service')) {
                $customTokens[] = $c;
            }
        }
        $customTokens = array_values(array_unique($customTokens));
        foreach ($customTokens as $c) {
            if (str_starts_with($c, 'custom_service_') && ! str_starts_with($c, 'custom_service_temp_')) {
                return $c;
            }
        }
        foreach ($customTokens as $c) {
            if (str_starts_with($c, 'custom_service_temp_')) {
                return $c;
            }
        }
        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        return null;
    }

    /**
     * First token that looks like a catalog slug (not a Firestore doc id, not custom_service_*).
     */
    protected function listingPrimaryCatalogSlugToken(array $d): ?string
    {
        foreach ($this->listingCategoryReferenceCandidates($d) as $c) {
            if ($c === '' || str_starts_with($c, 'custom_service')) {
                continue;
            }
            if (strlen($c) >= 18 && preg_match('/^[A-Za-z0-9]+$/', $c)) {
                continue;
            }

            return Str::limit($c, 191);
        }

        return null;
    }

    protected function uniqueCategorySlugForInsert(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }
        $slug = Str::limit($slug, 191, '');
        if (Category::query()->where('slug', $slug)->exists()) {
            return null;
        }

        return $slug;
    }

    /**
     * Prefer specific labels over generic "Other"; skip empty display strings so later keys apply.
     * Exact name match (any depth), prefer root.
     */
    protected function resolveCategoryFromListingName(array $d, bool $dryRun): ?int
    {
        $name = $this->firstNonEmptyListingCategoryDisplayName($d);
        if ($name === null) {
            return null;
        }

        $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        if ($normalized === '') {
            return null;
        }

        $existing = Category::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return null;
    }

    protected function firstNonEmptyListingCategoryDisplayName(array $d): ?string
    {
        $candidates = [
            data_get($d, 'custom_fields.main_service_category_name'),
            data_get($d, 'customFields.main_service_category_name'),
            $d['mainServiceCategoryDisplayName'] ?? null,
            $d['main_service_category_name'] ?? null,
            $d['categoryName'] ?? null,
            $d['category_name'] ?? null,
        ];
        foreach ($candidates as $v) {
            $t = FirestoreDataNormalizer::trimString(is_string($v) ? $v : null);
            if ($t === null) {
                continue;
            }
            if (strtolower($t) === 'other') {
                continue;
            }

            return $t;
        }

        foreach ([$d['categoryName'] ?? null, $d['category_name'] ?? null] as $v) {
            $t = FirestoreDataNormalizer::trimString(is_string($v) ? $v : null);
            if ($t !== null) {
                return $t;
            }
        }

        return null;
    }
}
