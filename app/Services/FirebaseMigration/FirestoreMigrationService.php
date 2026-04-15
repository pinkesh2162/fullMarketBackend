<?php

namespace App\Services\FirebaseMigration;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Store;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        return (string) config('firebase-migration.imported_user_password_hash');
    }

    /**
     * @return array{ok:int,skip:int,err:int}
     */
    public function run(?int $limit, bool $dryRun, bool $skipMedia, ?callable $line = null): array
    {
        $line ??= static function (): void {};

        $totals = ['ok' => 0, 'skip' => 0, 'err' => 0];

        foreach (['runCategories', 'runUsers', 'runStores', 'runListings'] as $step) {
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
        $folder = (string) config('firebase-migration.categories_folder', 'categories');
        $paths = $this->reader->jsonFiles($folder);
        $docs = [];
        foreach ($paths as $path) {
            $doc = $this->reader->readDocument($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $this->logger->skip('category', 'invalid_json', ['path' => $path]);
                continue;
            }
            $fbId = (string) $doc['__id'];
            $docs[$fbId] = FirestoreDataNormalizer::utf8Recursive($doc['data']);
        }

        $ordered = $this->orderCategoriesForInsert($docs);
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

            $dedupKey = $this->categoryDedupKey($name, $parentLocal);
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
                $cat = Category::query()->create([
                    'user_id' => null,
                    'name' => $name,
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

    protected function categoryDedupKey(string $name, ?int $parentLocalId): string
    {
        $n = strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));

        return $n.'|'.($parentLocalId ?? 0);
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
     *   location:?string,
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
        $location = $this->userLocationPayload($d);
        $locationJson = $location !== null ? json_encode($location, JSON_UNESCAPED_UNICODE) : null;
        $description = FirestoreDataNormalizer::truncateUtf8(
            FirestoreDataNormalizer::trimString($d['description'] ?? $d['bio'] ?? null)
        );
        $lang = FirestoreDataNormalizer::trimString($prefs['language'] ?? $d['lang'] ?? null) ?? 'en';
        $currency = FirestoreDataNormalizer::trimString($prefs['currency'] ?? null);
        $verified = FirestoreDataNormalizer::truthy($d['email_verified'] ?? $d['emailVerified'] ?? null);
        $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
        $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);
        $emailVerifiedAt = $verified ? ($created ?? $updated) : null;
        [$provider, $providerId] = $this->providerFromFirebaseUser($firebaseUid, $d);

        return [
            'first_name' => Str::limit((string) $first, 255, ''),
            'last_name' => $last !== null ? Str::limit($last, 255, '') : null,
            'phone' => $phone !== null ? Str::limit($phone, 255, '') : null,
            'phone_code' => $phoneCode !== null ? Str::limit($phoneCode, 32, '') : null,
            'location' => $locationJson,
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
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>|null
     */
    protected function userLocationPayload(array $d): ?array
    {
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

    /**
     * @param  mixed  $locations
     */
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
     * @param  mixed  $loc
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
                $this->logger->skip('listing', 'invalid_json', ['path' => $path]);
                $err++;
                continue;
            }
            $fbId = (string) $doc['__id'];
            $d = FirestoreDataNormalizer::utf8Recursive($doc['data']);
            $n++;

            $mapped = $this->state->getListingId($fbId);
            if ($mapped !== null && Listing::withTrashed()->whereKey($mapped)->exists()) {
                $listing = Listing::withTrashed()->find($mapped);
                if ($listing && $listing->trashed()) {
                    $listing->restore();
                }
                $skip++;
                continue;
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

            $categoryId = $this->categoryResolver->resolveLocalCategoryId($d);
            if ($categoryId === null) {
                $categoryId = $this->resolveCategoryFromListingName($d, $dryRun);
            }
            if ($categoryId === null) {
                $this->logger->skip('listing', 'unresolved_category', ['firebase_doc' => $fbId]);
                $skip++;
                continue;
            }
            if (! Category::query()->whereKey($categoryId)->exists()) {
                $this->logger->skip('listing', 'category_missing_in_db', ['firebase_doc' => $fbId, 'category_id' => $categoryId]);
                $skip++;
                continue;
            }

            $title = FirestoreDataNormalizer::trimString($d['name'] ?? $d['title'] ?? null);
            if ($title === null) {
                $this->logger->skip('listing', 'empty_title', ['firebase_doc' => $fbId]);
                $skip++;
                continue;
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
                    'user_id' => $userId,
                    'store_id' => $storeId,
                    'service_type' => $serviceType,
                    'title' => Str::limit($title, 500, ''),
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
                if ($created || $updated) {
                    DB::table('listings')->where('id', $listing->id)->update(array_filter([
                        'created_at' => $created,
                        'updated_at' => $updated ?? $created,
                    ]));
                }

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
     * @param  array<string, mixed>  $d
     */
    protected function resolveListingStoreId(array $d, string $ownerUid, int $userId): ?int
    {
        $author = $d['author_id'] ?? $d['authorId'] ?? null;
        if ($author === null || $author === '') {
            $sid = $this->state->getStoreIdByOwnerUid($ownerUid);
            if ($sid !== null) {
                return $sid;
            }

            return Store::query()->where('user_id', $userId)->value('id');
        }
        $key = trim((string) $author);
        $byDoc = $this->state->getStoreIdByDocId($key);
        if ($byDoc !== null) {
            return $byDoc;
        }
        $byOwner = $this->state->getStoreIdByOwnerUid($key);
        if ($byOwner !== null) {
            return $byOwner;
        }
        $store = Store::query()->where('user_id', $userId)->value('id');

        return $store !== null ? (int) $store : null;
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
     * When Firestore category ids are custom/non-migrated, fallback to category name.
     */
    protected function resolveCategoryFromListingName(array $d, bool $dryRun): ?int
    {
        $name = FirestoreDataNormalizer::trimString(
            $d['categoryName']
            ?? $d['category_name']
            ?? data_get($d, 'custom_fields.main_service_category_name')
            ?? null
        );
        if ($name === null) {
            return null;
        }

        $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        if ($normalized === '') {
            return null;
        }

        $existing = Category::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->whereNull('parent_id')
            ->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        if ($dryRun) {
            return null;
        }

        $created = Category::query()->create([
            'user_id' => null,
            'name' => Str::limit($name, 255, ''),
            'parent_id' => null,
        ]);

        return (int) $created->id;
    }
}
