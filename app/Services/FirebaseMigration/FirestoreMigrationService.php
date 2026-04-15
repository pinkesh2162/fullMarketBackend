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
                $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
                if ($existing !== null) {
                    $this->state->setUserId($fbUid, (int) $existing->id);
                    $this->state->save();
                    $this->syncUserSettings((int) $existing->id, $d, $dryRun);
                    $skip++;
                    continue;
                }

                $prefs = is_array($d['preferences'] ?? null) ? $d['preferences'] : [];
                $phone = FirestoreDataNormalizer::trimString($d['phonenumber'] ?? $d['phone'] ?? $d['phoneNumber'] ?? null);
                $location = $this->userLocationFromLocations($d['locations'] ?? null);
                $locationJson = $location !== null ? json_encode($location, JSON_UNESCAPED_UNICODE) : null;
                $bio = FirestoreDataNormalizer::truncateUtf8(FirestoreDataNormalizer::trimString($d['bio'] ?? $d['description'] ?? null));
                $lang = FirestoreDataNormalizer::trimString($prefs['language'] ?? $d['lang'] ?? null) ?? 'en';
                $currency = FirestoreDataNormalizer::trimString($prefs['currency'] ?? null);

                $verified = FirestoreDataNormalizer::truthy($d['email_verified'] ?? $d['emailVerified'] ?? null);
                $created = FirestoreDataNormalizer::parseTimestamp($d['createdAt'] ?? $d['createdat'] ?? null);
                $updated = FirestoreDataNormalizer::parseTimestamp($d['updatedAt'] ?? $d['updatedat'] ?? null);
                $emailVerifiedAt = $verified ? ($created ?? $updated) : null;

                $user = new User;
                $user->mergeCasts(['password' => 'string']);
                $user->fill([
                    'first_name' => Str::limit($first, 255, ''),
                    'last_name' => $last !== null ? Str::limit($last, 255, '') : null,
                    'email' => $email,
                    'password' => $this->passwordHash(),
                    'phone' => $phone !== null ? Str::limit($phone, 255, '') : null,
                    'location' => $locationJson,
                    'description' => $bio,
                    'lang' => Str::limit($lang, 16, ''),
                    'currency' => $currency !== null ? Str::limit($currency, 16, '') : null,
                    'email_verified_at' => $emailVerifiedAt,
                    'provider' => 'firebase',
                    'provider_id' => $fbUid,
                    'fcm_token' => FirestoreDataNormalizer::trimString($d['fcmtoken'] ?? $d['fcmToken'] ?? null),
                ]);
                $user->save();

                if ($created || $updated) {
                    DB::table('users')->where('id', $user->id)->update(array_filter([
                        'created_at' => $created,
                        'updated_at' => $updated ?? $created,
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
        $label = FirestoreDataNormalizer::trimString($first['description'] ?? $first['name'] ?? null);
        if ($label === null) {
            return null;
        }

        return ['label' => Str::limit($label, 500, '')];
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
                $userId = User::query()->where('provider', 'firebase')->where('provider_id', $ownerUid)->value('id');
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

        return array_filter([
            'id' => isset($loc['id']) ? (is_scalar($loc['id']) ? (string) $loc['id'] : null) : null,
            'lat' => $loc['lat'] ?? $loc['latitude'] ?? null,
            'lng' => $loc['lng'] ?? $loc['longitude'] ?? $loc['long'] ?? null,
            'name' => FirestoreDataNormalizer::trimString($loc['name'] ?? $loc['address'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
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

        return array_filter([
            'phone' => FirestoreDataNormalizer::trimString($c['phone'] ?? $c['phone_national'] ?? null),
            'whatsapp' => FirestoreDataNormalizer::trimString($c['whatsapp'] ?? $c['whatsapp_number'] ?? null),
            'email' => FirestoreDataNormalizer::normalizeEmail(FirestoreDataNormalizer::trimString($c['email'] ?? null)),
            'website' => FirestoreDataNormalizer::trimString($c['website'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
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

        return array_filter([
            'facebook' => FirestoreDataNormalizer::trimString($s['facebook'] ?? null),
            'instagram' => FirestoreDataNormalizer::trimString($s['instagram'] ?? null),
            'twitter' => FirestoreDataNormalizer::trimString($s['twitter'] ?? null),
            'linkedin' => FirestoreDataNormalizer::trimString($s['linkedin'] ?? null),
            'youtube' => FirestoreDataNormalizer::trimString($s['youtube'] ?? null),
            'tiktok' => FirestoreDataNormalizer::trimString($s['tiktok'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function openingHours(array $d): ?array
    {
        $oh = $d['opening_hours'] ?? $d['openingHours'] ?? null;
        if (is_array($oh)) {
            return $oh;
        }
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? null;
        if (is_array($cf) && isset($cf['opening_hours']) && is_array($cf['opening_hours'])) {
            return $cf['opening_hours'];
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
                $userId = User::query()->where('provider', 'firebase')->where('provider_id', $ownerUid)->value('id');
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
                $availability = $this->isActiveStatus($d['status'] ?? null);
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
                    'bedrooms' => $this->strOrNull($d['bedrooms'] ?? null),
                    'bathrooms' => $this->strOrNull($d['bathrooms'] ?? null),
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
        if (is_array($cf) && isset($cf['service_modality']) && is_string($cf['service_modality'])) {
            return Str::limit($cf['service_modality'], 120, '');
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

        return array_filter([
            'email' => FirestoreDataNormalizer::normalizeEmail(FirestoreDataNormalizer::trimString($c['email'] ?? null)),
            'phone' => FirestoreDataNormalizer::trimString($c['phone'] ?? null),
            'whatsapp' => FirestoreDataNormalizer::trimString($c['whatsapp'] ?? null),
            'website' => FirestoreDataNormalizer::trimString($c['website'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    protected function listingAdditional(array $d, string $fbDocId): array
    {
        return array_filter([
            'firebase' => ['document_id' => $fbDocId],
            'location' => $this->listingLocation($d),
        ], fn ($v) => $v !== null && $v !== []);
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
        if (! is_array($loc)) {
            return null;
        }

        return [
            'address' => $loc['address'] ?? null,
            'lat' => $loc['lat'] ?? $loc['latitude'] ?? null,
            'long' => $loc['long'] ?? $loc['lng'] ?? $loc['longitude'] ?? null,
        ];
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
        if ($serviceType !== Listing::PROPERTY_FOR_SALE) {
            return null;
        }
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
        $sr = is_array($cf) ? ($cf['sale_rent'] ?? null) : null;
        $lt = $d['listing_type'] ?? $d['listingType'] ?? null;
        if (is_string($lt) && strcasecmp($lt, 'rent') === 0) {
            return Listing::FOR_RENT;
        }
        if (is_string($sr) && strtolower($sr) === 'rent') {
            return Listing::FOR_RENT;
        }

        return Listing::FOR_SALE;
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
            'amenities' => $cf['amenities'] ?? null,
            'laundry' => $cf['laundry'] ?? $d['laundry'] ?? null,
            'parking' => $cf['parking'] ?? $d['parking'] ?? null,
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
            'mileage' => $cf['mileage'] ?? $d['mileage'] ?? null,
            'fuel_type' => $cf['fuel_type'] ?? null,
            'number_of_owners' => $cf['number_of_owners'] ?? null,
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
