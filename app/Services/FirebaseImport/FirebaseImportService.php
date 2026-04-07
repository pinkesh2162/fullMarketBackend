<?php

namespace App\Services\FirebaseImport;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Store;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FirebaseImportService
{
    public function __construct(
        protected ImportState $state
    ) {}

    protected function exportsRoot(): string
    {
        return rtrim(config('firebase-import.exports_path'), DIRECTORY_SEPARATOR);
    }

    protected function mediaDisk(): string
    {
        return config('firebase-import.media_disk', 'public');
    }

    /**
     * @return list<string>
     */
    protected function jsonFilesIn(string $collectionFolder): array
    {
        $dir = $this->exportsRoot().DIRECTORY_SEPARATOR.$collectionFolder;
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];

        return array_values(array_filter($files, is_file(...)));
    }

    /**
     * @return array{__id?:string,__path?:string,data?:array}|null
     */
    protected function readExport(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $json = json_decode($raw, true, 512);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('firebase-import: json decode failed', [
                'path' => $path,
                'error' => json_last_error_msg(),
            ]);

            return null;
        }

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeUtf8Recursive(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $k = is_string($key) ? $this->sanitizeUtf8String($key) : $key;
            if (is_string($value)) {
                $out[$k] = $this->sanitizeUtf8String($value);
            } elseif (is_array($value)) {
                $out[$k] = $this->normalizeUtf8Recursive($value);
            } else {
                $out[$k] = $value;
            }
        }

        return $out;
    }

    /**
     * MySQL TEXT is 65535 bytes; UTF-8 multi-byte chars can overflow if we only limit by character count.
     */
    protected function truncateUtf8ToMysqlText(?string $s, int $maxBytes = 60000): ?string
    {
        if ($s === null) {
            return null;
        }
        if ($s === '') {
            return '';
        }
        if (strlen($s) <= $maxBytes) {
            return $s;
        }
        $out = $s;
        while (strlen($out) > $maxBytes && mb_strlen($out, 'UTF-8') > 0) {
            $out = mb_substr($out, 0, -1, 'UTF-8');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    protected function jsonColumnArrayOrNull(?array $data, int $maxBytes = 60000): ?array
    {
        if ($data === null || $data === []) {
            return null;
        }
        try {
            $enc = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return null;
        }
        if (strlen($enc) <= $maxBytes) {
            return $data;
        }

        return null;
    }

    protected function sanitizeUtf8String(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $s)) {
            $s = (string) preg_replace_callback(
                '/\\\\u([0-9a-fA-F]{4})/',
                static fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8'),
                $s
            );
        }
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);

        return $fixed !== false ? $fixed : $s;
    }

    /**
     * Firestore / Firebase export booleans and emailVerified (camelCase).
     */
    protected function firestoreTruthy(mixed $v): bool
    {
        if ($v === null) {
            return false;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        if (is_string($v)) {
            $t = strtolower(trim($v));

            return in_array($t, ['1', 'true', 'yes'], true);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row  e.g. author, user doc, admin doc
     */
    protected function firestoreRowEmailVerified(array $row): bool
    {
        return $this->firestoreTruthy($row['emailVerified'] ?? null)
            || $this->firestoreTruthy($row['email_verified'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $info  merged profile for one Firebase UID
     */
    protected function importProfileEmailIsVerified(array $info): bool
    {
        if (! empty($info['email_verified'])) {
            return true;
        }

        return $this->firestoreTruthy($info['emailVerified'] ?? null);
    }

    protected function timestampIso(?array $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        if (isset($value['iso']) && is_string($value['iso'])) {
            return $value['iso'];
        }

        return null;
    }

    /**
     * @param  callable(string):void  $line
     * @return array{ok:int, skip:int, err:int}
     */
    public function importCategories(callable $line, bool $dryRun, ?int $limit): array
    {
        $paths = $this->jsonFilesIn('categories');
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        usort($paths, fn ($a, $b) => strcmp(basename($a), basename($b)));

        $pass1 = [];
        foreach ($paths as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $doc = $this->readExport($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $err++;

                continue;
            }
            $id = (string) $doc['__id'];
            $data = $this->normalizeUtf8Recursive($doc['data']);
            $parentFb = isset($data['parentId']) && is_string($data['parentId']) ? $data['parentId'] : null;
            $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : $id;
            $pass1[] = compact('id', 'name', 'parentFb', 'data', 'path');
            $n++;
        }

        $byParent = [];
        foreach ($pass1 as $row) {
            $key = $row['parentFb'] ?? '_root_';
            $byParent[$key] ??= [];
            $byParent[$key][] = $row;
        }

        $process = function (array $rows) use (&$process, &$ok, &$skip, &$err, $dryRun, $line) {
            foreach ($rows as $row) {
                $id = $row['id'];
                $mappedCatId = $this->state->getCategoryId($id);
                if ($mappedCatId !== null) {
                    if (Category::query()->whereKey($mappedCatId)->exists()) {
                        $skip++;

                        continue;
                    }
                    $this->state->forgetCategory($id);
                }

                $parentId = null;
                if ($row['parentFb'] !== null) {
                    $parentId = $this->state->getCategoryId($row['parentFb']);
                    if ($parentId === null) {
                        $line("Category {$id}: parent {$row['parentFb']} missing, importing as root");
                    }
                }

                if ($dryRun) {
                    $ok++;

                    continue;
                }

                try {
                    $cat = Category::query()->create([
                        'user_id' => null,
                        'name' => Str::limit($row['name'], 250, ''),
                        'parent_id' => $parentId,
                    ]);
                    $this->state->setCategoryId($id, $cat->id);
                    $image = $row['data']['image'] ?? null;
                    if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
                        try {
                            $cat->addMediaFromUrl($image)->toMediaCollection(Category::CATEGORY_IMAGE, $this->mediaDisk());
                        } catch (Throwable $e) {
                            Log::warning('firebase-import: category image failed', ['id' => $id, 'e' => $e->getMessage()]);
                        }
                    }
                    $ok++;
                } catch (Throwable $e) {
                    Log::error('firebase-import: category', ['id' => $id, 'e' => $e->getMessage()]);
                    $err++;
                }
            }
        };

        $process($byParent['_root_'] ?? []);

        $remaining = array_diff(array_keys($byParent), ['_root_']);
        $guard = 0;
        while ($remaining !== [] && $guard < 50) {
            $guard++;
            $nextRemaining = [];
            foreach ($remaining as $p) {
                if ($this->state->getCategoryId($p) === null) {
                    $nextRemaining[] = $p;

                    continue;
                }
                $process($byParent[$p] ?? []);
            }
            $remaining = $nextRemaining;
        }

        if (! $dryRun) {
            $this->state->save();
        }

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @return array<string, int>
     */
    public function userImportSourceJsonCounts(): array
    {
        $usersFolder = (string) config('firebase-import.users_collection', 'users');

        return [
            'favorites' => count($this->jsonFilesIn('favorites')),
            'listings' => count($this->jsonFilesIn('listings')),
            'admins' => count($this->jsonFilesIn('admins')),
            'users' => count($this->jsonFilesIn($usersFolder)),
        ];
    }

    /**
     * @param  callable(string):void  $line
     * @return array{ok:int, skip:int, err:int, pwd_updated:int, profiles_count:int, source_json_files:array<string,int>, exports_root:string, exports_root_exists:bool}
     */
    public function importUsers(callable $line, bool $dryRun, ?int $limit, bool $updatePasswords = false): array
    {
        $profiles = [];
        $defaultPassword = (string) config('firebase-import.default_password', '$2y$12$V4bA5J/7xk1mmqun6o.FQ.LAz8s6mSRQ/aU7JC3VvITUgdSdKQVYe');
        $usersFolder = (string) config('firebase-import.users_collection', 'users');

        foreach ($this->jsonFilesIn('favorites') as $path) {
            $doc = $this->readExport($path);
            if (! is_array($doc)) {
                continue;
            }
            $d = $doc['data'] ?? null;
            if (! is_array($d)) {
                continue;
            }
            $d = $this->normalizeUtf8Recursive($d);
            $userId = isset($d['userId']) ? (string) $d['userId'] : null;
            if ($userId) {
                $profiles[$userId] ??= [];
                $profiles[$userId]['_needs_stub'] = true;
            }
            $item = $d['itemData'] ?? null;
            if (! is_array($item)) {
                continue;
            }
            $author = $item['author'] ?? null;
            if (is_array($author)) {
                $uid = isset($author['uid']) ? (string) $author['uid'] : (isset($author['id']) ? (string) $author['id'] : null);
                if ($uid) {
                    $this->mergeAuthorProfile($profiles, $uid, $author);
                }
            }
        }

        foreach ($this->jsonFilesIn('listings') as $path) {
            $doc = $this->readExport($path);
            if (! is_array($doc)) {
                continue;
            }
            $d = $doc['data'] ?? null;
            if (! is_array($d)) {
                continue;
            }
            $d = $this->normalizeUtf8Recursive($d);
            $uid = $d['ownerId'] ?? $d['author_id'] ?? null;
            if (! is_string($uid) || $uid === '') {
                continue;
            }
            $profiles[$uid] ??= [];
            $this->mergeListingOwnerProfile($profiles, $uid, $d);
        }

        foreach ($this->jsonFilesIn('admins') as $path) {
            $doc = $this->readExport($path);
            if (! is_array($doc)) {
                continue;
            }
            $d = $doc['data'] ?? null;
            if (! is_array($d)) {
                continue;
            }
            $d = $this->normalizeUtf8Recursive($d);
            $uid = isset($d['userId']) ? (string) $d['userId'] : null;
            if (! $uid) {
                continue;
            }
            $profiles[$uid] ??= [];
            if (isset($d['email']) && is_string($d['email']) && filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
                $profiles[$uid]['email'] = $d['email'];
            }
            if (isset($d['displayName']) && is_string($d['displayName'])) {
                $parts = preg_split('/\s+/', trim($d['displayName']), 2);
                $profiles[$uid]['first_name'] = $parts[0] ?? 'Admin';
                $profiles[$uid]['last_name'] = $parts[1] ?? '';
            }
            if ($this->firestoreRowEmailVerified($d)) {
                $profiles[$uid]['email_verified'] = true;
            }
        }

        foreach ($this->jsonFilesIn($usersFolder) as $path) {
            $doc = $this->readExport($path);
            if (! is_array($doc) || ! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                continue;
            }
            $uid = (string) $doc['__id'];
            $d = $this->normalizeUtf8Recursive($doc['data']);
            $profiles[$uid] ??= [];
            $this->mergeUsersCollectionProfile($profiles, $uid, $d);
        }

        $ok = 0;
        $skip = 0;
        $err = 0;
        $pwdUpdated = 0;
        $i = 0;

        foreach ($profiles as $firebaseUid => $info) {
            if ($limit !== null && $i >= $limit) {
                break;
            }
            $i++;

            $existing = User::query()->where('provider', 'firebase')->where('provider_id', $firebaseUid)->first();
            if ($existing) {
                $this->state->setUserId($firebaseUid, (int) $existing->id);
                if (! $dryRun) {
                    $dirty = false;
                    if ($updatePasswords) {
                        $existing->password = $defaultPassword;
                        $dirty = true;
                        $pwdUpdated++;
                    }
                    if ($this->importProfileEmailIsVerified($info)) {
                        $existing->email_verified_at = now();
                        $dirty = true;
                    }
                    if ($dirty) {
                        $existing->save();
                    }
                }
                $skip++;

                continue;
            }

            $email = $this->pickEmail($firebaseUid, $info);
            $first = isset($info['first_name']) && is_string($info['first_name']) ? $info['first_name'] : 'User';
            $last = isset($info['last_name']) && is_string($info['last_name']) ? $info['last_name'] : '';
            $phone = isset($info['phone']) && is_string($info['phone']) ? $info['phone'] : null;
            $lang = isset($info['lang']) && is_string($info['lang']) ? Str::limit($info['lang'], 8, '') : 'en';
            $fcm = isset($info['fcm_token']) && is_string($info['fcm_token']) ? $info['fcm_token'] : null;
            $avatar = isset($info['avatar']) && is_string($info['avatar']) ? $info['avatar'] : null;

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $user = User::query()->create([
                    'first_name' => Str::limit($first, 120, ''),
                    'last_name' => $last !== '' ? Str::limit($last, 120, '') : null,
                    'email' => $email,
                    'password' => $defaultPassword,
                    'phone' => $phone ? Str::limit($phone, 50, '') : null,
                    'phone_code' => isset($info['phone_code']) && is_string($info['phone_code']) ? Str::limit($info['phone_code'], 12, '') : null,
                    'location' => isset($info['location']) && is_array($info['location']) ? $info['location'] : null,
                    'description' => isset($info['description']) && is_string($info['description']) ? $info['description'] : null,
                    'lang' => $lang,
                    'provider' => 'firebase',
                    'provider_id' => $firebaseUid,
                    'fcm_token' => $fcm ? Str::limit($fcm, 512, '') : null,
                    'email_verified_at' => $this->importProfileEmailIsVerified($info) ? now() : null,
                ]);

                $this->state->setUserId($firebaseUid, (int) $user->id);

                $prefs = $info['notification_prefs'] ?? null;
                UserSetting::query()->create([
                    'user_id' => $user->id,
                    'hide_ads' => false,
                    'notification_post' => (bool) data_get($prefs, 'push', true),
                    'message_notification' => (bool) data_get($prefs, 'chat', true),
                    'business_create' => true,
                    'follow_request' => true,
                ]);

                if ($avatar && filter_var($avatar, FILTER_VALIDATE_URL)) {
                    try {
                        $user->addMediaFromUrl($avatar)->toMediaCollection(User::PROFILE, $this->mediaDisk());
                    } catch (Throwable $e) {
                        Log::warning('firebase-import: user avatar failed', ['uid' => $firebaseUid, 'e' => $e->getMessage()]);
                    }
                }

                $ok++;
            } catch (Throwable $e) {
                Log::error('firebase-import: user', ['uid' => $firebaseUid, 'e' => $e->getMessage()]);
                $line("User error {$firebaseUid}: {$e->getMessage()}");
                $err++;
            }
        }

        if (! $dryRun) {
            $this->state->save();
        }

        $exportsRoot = $this->exportsRoot();

        return [
            'ok' => $ok,
            'skip' => $skip,
            'err' => $err,
            'pwd_updated' => $pwdUpdated,
            'profiles_count' => count($profiles),
            'source_json_files' => $this->userImportSourceJsonCounts(),
            'exports_root' => $exportsRoot,
            'exports_root_exists' => is_dir($exportsRoot),
        ];
    }

    /**
     * @param  array<string, mixed>  $profiles
     * @param  array<string, mixed>  $d
     */
    protected function mergeUsersCollectionProfile(array &$profiles, string $uid, array $d): void
    {
        $profiles[$uid]['_needs_stub'] = false;
        if (isset($d['email']) && is_string($d['email']) && filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $profiles[$uid]['email'] = $d['email'];
        }
        if (isset($d['displayName']) && is_string($d['displayName'])) {
            $parts = preg_split('/\s+/', trim($d['displayName']), 2);
            if (empty($profiles[$uid]['first_name'])) {
                $profiles[$uid]['first_name'] = $parts[0] ?? 'User';
            }
            if (empty($profiles[$uid]['last_name'])) {
                $profiles[$uid]['last_name'] = $parts[1] ?? '';
            }
        }
        foreach (['firstName' => 'first_name', 'lastName' => 'last_name'] as $src => $dst) {
            if (isset($d[$src]) && is_string($d[$src])) {
                $profiles[$uid][$dst] = $d[$src];
            }
        }
        foreach (['first_name', 'last_name'] as $k) {
            if (isset($d[$k]) && is_string($d[$k])) {
                $profiles[$uid][$k] = $d[$k];
            }
        }
        if (isset($d['photoURL']) && is_string($d['photoURL'])) {
            $profiles[$uid]['avatar'] = $d['photoURL'];
        } elseif (isset($d['avatar']) && is_string($d['avatar'])) {
            $profiles[$uid]['avatar'] = $d['avatar'];
        } elseif (isset($d['pp_thumb_url']) && is_string($d['pp_thumb_url'])) {
            $profiles[$uid]['avatar'] = $d['pp_thumb_url'];
        }
        if (isset($d['phoneNumber']) && is_string($d['phoneNumber']) && $d['phoneNumber'] !== '') {
            $profiles[$uid]['phone'] = $d['phoneNumber'];
        } elseif (isset($d['phone']) && is_string($d['phone']) && $d['phone'] !== '') {
            $profiles[$uid]['phone'] = $d['phone'];
        }
        if ($this->firestoreRowEmailVerified($d)) {
            $profiles[$uid]['email_verified'] = true;
        }
        $lang = data_get($d, 'preferences.language');
        if (is_string($lang) && $lang !== '') {
            $profiles[$uid]['lang'] = $lang;
        }
        $notif = data_get($d, 'preferences.notifications');
        if (is_array($notif)) {
            $profiles[$uid]['notification_prefs'] = $notif;
        }
    }

    /**
     * @param  array<string, mixed>  $profiles
     * @param  array<string, mixed>  $author
     */
    protected function mergeAuthorProfile(array &$profiles, string $uid, array $author): void
    {
        $profiles[$uid]['_needs_stub'] = false;
        if (isset($author['email']) && is_string($author['email']) && filter_var($author['email'], FILTER_VALIDATE_EMAIL)) {
            $profiles[$uid]['email'] = $author['email'];
        }
        if (isset($author['firstName']) && is_string($author['firstName'])) {
            $profiles[$uid]['first_name'] = $author['firstName'];
        } elseif (isset($author['first_name']) && is_string($author['first_name'])) {
            $profiles[$uid]['first_name'] = $author['first_name'];
        }
        if (isset($author['lastName']) && is_string($author['lastName'])) {
            $profiles[$uid]['last_name'] = $author['lastName'];
        } elseif (isset($author['last_name']) && is_string($author['last_name'])) {
            $profiles[$uid]['last_name'] = $author['last_name'];
        }
        if (empty($profiles[$uid]['first_name']) && isset($author['displayName']) && is_string($author['displayName'])) {
            $parts = preg_split('/\s+/', trim($author['displayName']), 2);
            $profiles[$uid]['first_name'] = $parts[0] ?? 'User';
            $profiles[$uid]['last_name'] = $parts[1] ?? '';
        }
        if (isset($author['pp_thumb_url']) && is_string($author['pp_thumb_url'])) {
            $profiles[$uid]['avatar'] = $author['pp_thumb_url'];
        } elseif (isset($author['avatar']) && is_string($author['avatar'])) {
            $profiles[$uid]['avatar'] = $author['avatar'];
        }
        if (isset($author['fcmToken']) && is_string($author['fcmToken'])) {
            $profiles[$uid]['fcm_token'] = $author['fcmToken'];
        }
        if (isset($author['phone']) && is_string($author['phone']) && $author['phone'] !== '') {
            $profiles[$uid]['phone'] = $author['phone'];
        }
        if ($this->firestoreRowEmailVerified($author)) {
            $profiles[$uid]['email_verified'] = true;
        }
        $lang = data_get($author, 'preferences.language');
        if (is_string($lang) && $lang !== '') {
            $profiles[$uid]['lang'] = $lang;
        }
        $notif = data_get($author, 'preferences.notifications');
        if (is_array($notif)) {
            $profiles[$uid]['notification_prefs'] = $notif;
        }
    }

    /**
     * @param  array<string, mixed>  $profiles
     * @param  array<string, mixed>  $d
     */
    protected function mergeListingOwnerProfile(array &$profiles, string $uid, array $d): void
    {
        $profiles[$uid]['_needs_stub'] = false;
        if (isset($d['email']) && is_string($d['email']) && filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $profiles[$uid]['email'] ??= $d['email'];
        }
        if (isset($d['ownerName']) && is_string($d['ownerName'])) {
            $parts = preg_split('/\s+/', trim($d['ownerName']), 2);
            if (empty($profiles[$uid]['first_name'])) {
                $profiles[$uid]['first_name'] = $parts[0] ?? 'User';
            }
            if (empty($profiles[$uid]['last_name'])) {
                $profiles[$uid]['last_name'] = $parts[1] ?? '';
            }
        }
        if (isset($d['ownerAvatar']) && is_string($d['ownerAvatar'])) {
            $profiles[$uid]['avatar'] ??= $d['ownerAvatar'];
        }
        if ($this->firestoreRowEmailVerified($d)) {
            $profiles[$uid]['email_verified'] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $info
     */
    protected function pickEmail(string $firebaseUid, array $info): string
    {
        $email = $info['email'] ?? null;
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $base = Str::lower($email);
            if (! User::query()->where('email', $base)->exists()) {
                return $base;
            }

            $at = strrpos($base, '@');
            if ($at !== false) {
                $local = substr($base, 0, $at);
                $domain = substr($base, $at);

                return $local.'+fb_'.$firebaseUid.$domain;
            }
        }

        return 'firebase_'.$firebaseUid.'@imported.local';
    }

    /**
     * When the users step did not create a row for this Firebase UID, create one from listing owner fields
     * so listings can satisfy the user_id foreign key.
     *
     * @param  callable(string):void  $line
     */
    protected function createFirebaseUserFromListingStub(string $uid, array $listingData, callable $line): ?int
    {
        $profiles = [];
        $profiles[$uid] = [];
        $this->mergeListingOwnerProfile($profiles, $uid, $listingData);
        $info = $profiles[$uid];

        $defaultPassword = (string) config('firebase-import.default_password', '$2y$12$V4bA5J/7xk1mmqun6o.FQ.LAz8s6mSRQ/aU7JC3VvITUgdSdKQVYe');

        try {
            $email = $this->pickEmail($uid, $info);
            $first = isset($info['first_name']) && is_string($info['first_name']) ? $info['first_name'] : 'User';
            $last = isset($info['last_name']) && is_string($info['last_name']) ? $info['last_name'] : '';
            $phone = isset($info['phone']) && is_string($info['phone']) ? $info['phone'] : null;
            $lang = isset($info['lang']) && is_string($info['lang']) ? Str::limit($info['lang'], 8, '') : 'en';
            $fcm = isset($info['fcm_token']) && is_string($info['fcm_token']) ? $info['fcm_token'] : null;
            $avatar = isset($info['avatar']) && is_string($info['avatar']) ? $info['avatar'] : null;
            $prefs = is_array($info['notification_prefs'] ?? null) ? $info['notification_prefs'] : [];

            $user = User::query()->create([
                'first_name' => Str::limit($first, 120, ''),
                'last_name' => $last !== '' ? Str::limit($last, 120, '') : null,
                'email' => $email,
                'password' => $defaultPassword,
                'phone' => $phone ? Str::limit($phone, 50, '') : null,
                'phone_code' => isset($info['phone_code']) && is_string($info['phone_code']) ? Str::limit($info['phone_code'], 12, '') : null,
                'location' => isset($info['location']) && is_array($info['location']) ? $info['location'] : null,
                'description' => isset($info['description']) && is_string($info['description']) ? $info['description'] : null,
                'lang' => $lang,
                'provider' => 'firebase',
                'provider_id' => $uid,
                'fcm_token' => $fcm ? Str::limit($fcm, 512, '') : null,
                'email_verified_at' => $this->importProfileEmailIsVerified($info) ? now() : null,
            ]);

            $this->state->setUserId($uid, (int) $user->id);

            UserSetting::query()->create([
                'user_id' => $user->id,
                'hide_ads' => false,
                'notification_post' => (bool) data_get($prefs, 'push', true),
                'message_notification' => (bool) data_get($prefs, 'chat', true),
                'business_create' => true,
                'follow_request' => true,
            ]);

            if ($avatar && filter_var($avatar, FILTER_VALIDATE_URL)) {
                try {
                    $user->addMediaFromUrl($avatar)->toMediaCollection(User::PROFILE, $this->mediaDisk());
                } catch (Throwable $e) {
                    Log::warning('firebase-import: stub user avatar failed', ['uid' => $uid, 'e' => $e->getMessage()]);
                }
            }

            $line("Created seller user for UID {$uid} (was missing after users import).");

            return (int) $user->id;
        } catch (Throwable $e) {
            Log::error('firebase-import: stub user from listing', ['uid' => $uid, 'e' => $e->getMessage()]);
            $line("Could not create seller {$uid}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @param  callable(string):void  $line
     * @return array{ok:int, skip:int, err:int}
     */
    public function importStores(callable $line, bool $dryRun, ?int $limit): array
    {
        $templates = [];

        foreach ($this->jsonFilesIn('listings') as $path) {
            $doc = $this->readExport($path);
            if (! is_array($doc)) {
                continue;
            }
            $d = $doc['data'] ?? null;
            if (! is_array($d)) {
                continue;
            }
            $d = $this->normalizeUtf8Recursive($d);
            $uid = $d['ownerId'] ?? $d['author_id'] ?? null;
            if (! is_string($uid) || $uid === '') {
                continue;
            }
            if (isset($templates[$uid])) {
                continue;
            }
            $templates[$uid] = $d;
        }

        foreach ($this->jsonFilesIn('favorites') as $path) {
            $doc = $this->readExport($path);
            if (! is_array($doc)) {
                continue;
            }
            $d = $doc['data'] ?? null;
            if (! is_array($d)) {
                continue;
            }
            $d = $this->normalizeUtf8Recursive($d);
            $item = $d['itemData'] ?? null;
            if (! is_array($item)) {
                continue;
            }
            $author = $item['author'] ?? null;
            if (! is_array($author)) {
                continue;
            }
            $uid = isset($author['uid']) ? (string) $author['uid'] : (isset($author['id']) ? (string) $author['id'] : null);
            if (! $uid || isset($templates[$uid])) {
                continue;
            }
            $templates[$uid] = [
                'ownerName' => $author['displayName'] ?? $author['name'] ?? 'Store',
                'from_favorite_author' => true,
                'author' => $author,
            ];
        }

        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($templates as $firebaseUid => $d) {
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $n++;

            $userId = $this->state->getUserId($firebaseUid);
            if ($userId === null) {
                $userId = User::query()->where('provider', 'firebase')->where('provider_id', $firebaseUid)->value('id');
                if ($userId === null) {
                    $line("Store skipped: no user for {$firebaseUid}");
                    $err++;

                    continue;
                }
                $this->state->setUserId($firebaseUid, (int) $userId);
            }

            $mappedStoreId = $this->state->getStoreId($firebaseUid);
            if ($mappedStoreId !== null) {
                if (Store::query()->whereKey($mappedStoreId)->exists()) {
                    $skip++;

                    continue;
                }
                $this->state->forgetStore($firebaseUid);
            }

            $existing = Store::query()->where('user_id', $userId)->first();
            if ($existing) {
                $this->state->setStoreId($firebaseUid, (int) $existing->id);
                $skip++;

                continue;
            }

            $name = 'My store';
            $location = null;
            $businessTime = null;
            $contactInformation = null;
            $socialMedia = null;

            if (! empty($d['from_favorite_author']) && isset($d['author']) && is_array($d['author'])) {
                $a = $d['author'];
                $name = is_string($a['storeName'] ?? null) && $a['storeName'] !== ''
                    ? $a['storeName']
                    : (is_string($a['displayName'] ?? null) ? $a['displayName'] : 'My store');
                $socialMedia = $this->socialFromAuthor($a);
                $contactInformation = $this->contactFromAuthor($a);
            } else {
                $name = is_string($d['name'] ?? null) && $d['name'] !== ''
                    ? $d['name']
                    : (is_string($d['title'] ?? null) ? $d['title'] : (is_string($d['ownerName'] ?? null) ? $d['ownerName'].' — store' : 'My store'));
                $name = Str::limit($name, 250, '');
                $location = $this->locationArrayFromListing($d);
                $businessTime = $this->businessHoursFromListing($d);
                $contactInformation = $this->contactInfoFromFirestore($d['contact'] ?? []);
                $socialMedia = $this->socialFromListing($d);
            }

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $store = Store::query()->create([
                    'user_id' => $userId,
                    'name' => $name,
                    'location' => $location,
                    'business_time' => $businessTime,
                    'contact_information' => $contactInformation,
                    'social_media' => $socialMedia,
                ]);
                $this->state->setStoreId($firebaseUid, (int) $store->id);

                $cover = data_get($d, 'author.storeBanner') ?? data_get($d, 'storeBanner');
                if (is_string($cover) && filter_var($cover, FILTER_VALIDATE_URL)) {
                    try {
                        $store->addMediaFromUrl($cover)->toMediaCollection(Store::COVER_PHOTO, $this->mediaDisk());
                    } catch (Throwable $e) {
                        Log::warning('firebase-import: store cover', ['e' => $e->getMessage()]);
                    }
                }
                $logo = data_get($d, 'author.storeLogo') ?? data_get($d, 'storeLogo');
                if (is_string($logo) && filter_var($logo, FILTER_VALIDATE_URL)) {
                    try {
                        $store->addMediaFromUrl($logo)->toMediaCollection(Store::PROFILE_PHOTO, $this->mediaDisk());
                    } catch (Throwable $e) {
                        Log::warning('firebase-import: store logo', ['e' => $e->getMessage()]);
                    }
                }

                $ok++;
            } catch (Throwable $e) {
                Log::error('firebase-import: store', ['uid' => $firebaseUid, 'e' => $e->getMessage()]);
                $err++;
            }
        }

        if (! $dryRun) {
            $this->state->save();
        }

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function locationArrayFromListing(array $d): ?array
    {
        $loc = $d['location'] ?? null;
        if (is_string($loc)) {
            $trim = trim($loc);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    $loc = $this->normalizeUtf8Recursive($decoded);
                }
            }
        }
        if (is_array($loc)) {
            return [
                'address' => $loc['address'] ?? $d['address'] ?? null,
                'city' => $loc['city'] ?? $d['city'] ?? null,
                'state' => $loc['state'] ?? $d['state'] ?? null,
                'country' => $loc['country'] ?? $d['country'] ?? null,
                'zipcode' => $loc['zipcode'] ?? $d['zipcode'] ?? null,
                'lat' => $loc['latitude'] ?? $loc['lat'] ?? $d['latitude'] ?? $d['lat'] ?? null,
                'long' => $loc['longitude'] ?? $loc['long'] ?? $loc['lng'] ?? $d['longitude'] ?? $d['long'] ?? $d['lng'] ?? null,
            ];
        }

        return [
            'address' => $d['address'] ?? null,
            'city' => $d['city'] ?? null,
            'state' => $d['state'] ?? null,
            'country' => $d['country'] ?? null,
            'zipcode' => $d['zipcode'] ?? null,
            'lat' => $d['latitude'] ?? $d['lat'] ?? null,
            'long' => $d['longitude'] ?? $d['long'] ?? $d['lng'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function additionalInfoForListing(array $d): array
    {
        $base = [
            'firebase' => [
                'document_id' => null,
                'status' => $d['status'] ?? null,
                'tags' => $d['tags'] ?? null,
            ],
            'location' => $this->locationArrayFromListing($d),
        ];

        return array_filter($base, fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    protected function contactInfoFromFirestore(array $contact): array
    {
        return array_filter([
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? $contact['phone_national'] ?? null,
            'phone_country_code' => $contact['phone_country_code'] ?? null,
            'phone_country_code_iso' => $contact['phone_country_code_iso'] ?? null,
            'whatsapp' => $contact['whatsapp'] ?? $contact['whatsapp_number'] ?? null,
            'whatsapp_country_code' => $contact['whatsapp_country_code'] ?? null,
            'website' => $contact['website'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function contactBlockForListing(array $d): array
    {
        $c = isset($d['contact']) && is_array($d['contact']) ? $d['contact'] : [];

        return $this->contactInfoFromFirestore($c);
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function socialFromListing(array $d): ?array
    {
        $sp = $d['socialProfiles'] ?? $d['social_profiles'] ?? [];
        if (! is_array($sp)) {
            $sp = [];
        }
        $out = array_filter([
            'facebook' => $d['social_facebook'] ?? $sp['facebook'] ?? null,
            'instagram' => $d['social_instagram'] ?? $sp['instagram'] ?? null,
            'tiktok' => $d['social_tiktok'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    protected function socialFromAuthor(array $a): ?array
    {
        $links = $a['socialLinks'] ?? [];
        if (! is_array($links)) {
            return null;
        }

        $out = array_filter([
            'facebook' => $links['facebook'] ?? null,
            'instagram' => $links['instagram'] ?? null,
            'linkedin' => $links['linkedin'] ?? null,
            'twitter' => $links['twitter'] ?? null,
            'youtube' => $links['youtube'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    protected function contactFromAuthor(array $a): ?array
    {
        return array_filter([
            'email' => $a['storeEmail'] ?? $a['email'] ?? null,
            'phone' => $a['storePhone'] ?? $a['phone'] ?? null,
            'website' => $a['storeWebsite'] ?? $a['website'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function businessHoursFromListing(array $d): ?array
    {
        $cf = $d['custom_fields'] ?? $d['customFields'] ?? null;
        if (! is_array($cf)) {
            return null;
        }
        $oh = $cf['opening_hours'] ?? null;

        return is_array($oh) ? $oh : null;
    }

    protected function mapServiceType(array $d): string
    {
        $ad = isset($d['ad_type']) && is_string($d['ad_type']) ? $d['ad_type'] : '';
        $lt = isset($d['listingType']) && is_string($d['listingType']) ? $d['listingType'] : '';

        return match (true) {
            $ad === 'property' || $lt === 'property' => Listing::PROPERTY_FOR_SALE,
            $ad === 'vehicle' || $lt === 'vehicle' => Listing::VEHICLE_FOR_SALE,
            $ad === 'service' || $lt === 'service' => Listing::OFFER_SERVICE,
            $ad === 'item', $ad === 'sell', $lt === 'sell' => Listing::ARTICLE_FOR_SALE,
            default => Listing::ARTICLE_FOR_SALE,
        };
    }

    protected function mapServiceModality(array $d): ?string
    {
        $m = $d['serviceModality'] ?? data_get($d, 'custom_fields.service_modality')
            ?? data_get($d, 'customFields.service_modality');
        if (is_string($m) && $m !== '') {
            return Str::limit($m, 120, '');
        }

        return null;
    }

    /**
     * Build image URLs in the same order Firebase uses: process `gallery` first (canonical), then `images`
     * for any extra URLs. Sort by each item's `order` field; dedupe by URL without reordering.
     *
     * @return list<string>
     */
    protected function collectListingImageUrlsOrdered(array $d): array
    {
        $rows = [];
        foreach (['gallery', 'images'] as $sourceIndex => $key) {
            $items = $d[$key] ?? null;
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $u = $item['url'] ?? $item['src'] ?? null;
                if (! is_string($u) || ! filter_var($u, FILTER_VALIDATE_URL)) {
                    $sizes = $item['sizes'] ?? null;
                    if (is_array($sizes) && isset($sizes['full']['src']) && is_string($sizes['full']['src'])) {
                        $u = $sizes['full']['src'];
                    }
                }
                if (! is_string($u) || ! filter_var($u, FILTER_VALIDATE_URL)) {
                    continue;
                }
                $order = isset($item['order']) ? (int) $item['order'] : 0;
                $rows[] = [
                    'source' => $sourceIndex,
                    'order' => $order,
                    'url' => $u,
                ];
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
     * Stable filename per listing + position so Spatie never reuses the same stored name across listings.
     */
    protected function uniqueMediaFileNameForUrl(int $listingId, int $position, string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        if ($ext === '' || strlen($ext) > 8 || ! preg_match('/^[a-z0-9]+$/', $ext)) {
            $ext = 'jpg';
        }

        return 'listing-'.$listingId.'-'.$position.'-'.Str::lower(Str::random(16)).'.'.$ext;
    }

    /**
     * @param  callable(string):void  $line
     * @return array{ok:int, skip:int, err:int}
     */
    public function importListings(callable $line, bool $dryRun, ?int $limit, bool $skipImages): array
    {
        $paths = $this->jsonFilesIn('listings');
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;
        $firstErrorMessage = null;

        foreach ($paths as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $n++;

            $doc = $this->readExport($path);
            if (! isset($doc['__id'], $doc['data']) || ! is_array($doc['data'])) {
                $err++;

                continue;
            }
            $fbId = (string) $doc['__id'];
            $d = $this->normalizeUtf8Recursive($doc['data']);

            $mappedListingId = $this->state->getListingId($fbId);
            if ($mappedListingId !== null) {
                $existingListing = Listing::withTrashed()->find($mappedListingId);
                if ($existingListing !== null) {
                    if ($existingListing->trashed()) {
                        $existingListing->restore();
                    }
                    $skip++;

                    continue;
                }
                $this->state->forgetListing($fbId);
            }

            $rawUid = $d['ownerId'] ?? $d['author_id'] ?? null;
            $uid = is_scalar($rawUid) ? trim((string) $rawUid) : '';
            if ($uid === '') {
                $line("Listing {$fbId}: missing owner");
                $err++;

                continue;
            }

            if ($dryRun) {
                $ok++;

                continue;
            }

            $userId = $this->state->getUserId($uid);
            if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
                $this->state->forgetUser($uid);
                $userId = null;
            }
            if ($userId === null) {
                $userId = User::query()->where('provider', 'firebase')->where('provider_id', $uid)->value('id');
            }
            if ($userId === null) {
                $stubId = $this->createFirebaseUserFromListingStub($uid, $d, $line);
                if ($stubId === null) {
                    $err++;

                    continue;
                }
                $userId = $stubId;
            }

            $storeId = $this->state->getStoreId($uid);
            if ($storeId !== null && ! Store::query()->whereKey($storeId)->exists()) {
                $this->state->forgetStore($uid);
                $storeId = null;
            }
            if ($storeId === null) {
                $storeId = Store::query()->where('user_id', $userId)->value('id');
            }

            $serviceType = $this->mapServiceType($d);
            $categoryId = $this->resolveCategoryId($d, (int) $userId);

            $title = $d['title'] ?? $d['name'] ?? 'Untitled';
            $title = is_string($title) ? Str::limit($title, 500, '') : 'Untitled';
            $description = $d['description'] ?? $d['content'] ?? '';
            $description = is_string($description) ? $this->truncateUtf8ToMysqlText($description) : '';
            $tags = $d['tags'] ?? [];
            $search = is_array($tags) ? implode(', ', array_map('strval', $tags)) : '';
            $search = $this->truncateUtf8ToMysqlText($search, 60000);

            $stats = isset($d['stats']) && is_array($d['stats']) ? $d['stats'] : [];
            $views = (int) ($stats['viewCount'] ?? 0);

            $availability = ($d['status'] ?? '') === 'active';

            $listingType = null;
            $propertyType = null;
            $bedrooms = null;
            $bathrooms = null;
            $advance = null;
            $vehicleType = null;
            $vehicalInfo = null;
            $fual = null;
            $transmission = null;

            if ($serviceType === Listing::PROPERTY_FOR_SALE) {
                $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
                $propertyType = is_string($d['propertyType'] ?? null) ? $d['propertyType'] : (is_array($cf) ? ($cf['property_type'] ?? null) : null);
                $propertyType = is_string($propertyType) ? Str::limit($propertyType, 120, '') : null;
                $bedrooms = isset($d['bedrooms']) ? (string) $d['bedrooms'] : null;
                $bathrooms = isset($d['bathrooms']) ? (string) $d['bathrooms'] : null;
                $sr = $d['saleRent'] ?? data_get($cf, 'sale_rent');
                $listingType = ($sr === 'rent' || $d['listingType'] === 'rent') ? Listing::FOR_RENT : Listing::FOR_SALE;
                $advance = is_array($cf) ? $this->jsonColumnArrayOrNull(array_filter([
                    'amenities' => $cf['amenities'] ?? null,
                    'laundry' => $cf['laundry'] ?? $d['laundry'] ?? null,
                    'parking' => $cf['parking'] ?? $d['parking'] ?? null,
                ])) : null;
            }

            if ($serviceType === Listing::VEHICLE_FOR_SALE) {
                $vehicleType = isset($d['vehicleType']) && is_string($d['vehicleType']) ? Str::limit($d['vehicleType'], 120, '') : null;
                $transmission = isset($d['transmission']) && is_string($d['transmission']) ? Str::limit($d['transmission'], 120, '') : null;
                $cf = $d['custom_fields'] ?? $d['customFields'] ?? [];
                $vehicalInfo = is_array($cf) ? $this->jsonColumnArrayOrNull(array_filter([
                    'brand' => $cf['brand'] ?? $d['brand'] ?? null,
                    'model' => $cf['model'] ?? $d['model'] ?? null,
                    'year' => $cf['year'] ?? $d['year'] ?? null,
                    'mileage' => $cf['mileage'] ?? $d['mileage'] ?? null,
                    'fuel_type' => $cf['fuel_type'] ?? null,
                    'number_of_owners' => $cf['number_of_owners'] ?? null,
                ])) : null;
                $fual = is_array($cf) && isset($cf['fuel_type']) && is_string($cf['fuel_type']) ? Str::limit($cf['fuel_type'], 120, '') : null;
            }

            $additional = $this->additionalInfoForListing($d);
            $additional['firebase']['document_id'] = $fbId;
            $additional = $this->jsonColumnArrayOrNull($additional) ?? ['firebase' => ['document_id' => $fbId, 'truncated' => true]];

            $priceVal = $d['price'] ?? null;
            $priceStr = $priceVal === null || $priceVal === '' ? null : (is_string($priceVal) ? $priceVal : (string) $priceVal);
            $currency = isset($d['currency']) && is_string($d['currency']) ? Str::limit($d['currency'], 16, '') : null;
            $condition = isset($d['condition']) && is_string($d['condition']) ? Str::limit($d['condition'], 120, '') : null;

            $contactBlock = $this->contactBlockForListing($d);

            try {
                $listing = Listing::query()->create([
                    'user_id' => $userId,
                    'store_id' => $storeId,
                    'service_type' => $serviceType,
                    'title' => $title,
                    'views_count' => $views,
                    'service_category' => $categoryId,
                    'service_modality' => $this->mapServiceModality($d),
                    'description' => $description,
                    'search_keyword' => ($search !== null && $search !== '') ? $search : null,
                    'contact_info' => $this->jsonColumnArrayOrNull($contactBlock === [] ? null : $contactBlock),
                    'additional_info' => $additional,
                    'currency' => $currency,
                    'price' => $priceStr,
                    'availability' => $availability,
                    'condition' => $condition,
                    'listing_type' => $listingType,
                    'property_type' => $propertyType,
                    'bedrooms' => $bedrooms,
                    'bathrooms' => $bathrooms,
                    'advance_options' => $advance,
                    'vehicle_type' => $vehicleType,
                    'vehical_info' => $vehicalInfo,
                    'fual_type' => $fual,
                    'transmission' => $transmission,
                ]);

                $created = $this->timestampIso($d['createdAt'] ?? null);
                $updated = $this->timestampIso($d['updatedAt'] ?? null);
                if ($created || $updated) {
                    DB::table('listings')->where('id', $listing->id)->update([
                        'created_at' => $created ? Carbon::parse($created) : $listing->created_at,
                        'updated_at' => $updated ? Carbon::parse($updated) : $listing->updated_at,
                    ]);
                }

                $this->state->setListingId($fbId, (int) $listing->id);

                if (! $skipImages) {
                    $listing->refresh();
                    $urls = $this->collectListingImageUrlsOrdered($d);
                    foreach ($urls as $position => $url) {
                        try {
                            $media = $listing->addMediaFromUrl($url)
                                ->usingFileName($this->uniqueMediaFileNameForUrl((int) $listing->id, $position, $url))
                                ->toMediaCollection(Listing::LISTING_IMAGES, $this->mediaDisk());
                            $media->order_column = $position;
                            $media->save();
                        } catch (Throwable $e) {
                            Log::warning('firebase-import: listing image', ['listing' => $fbId, 'e' => $e->getMessage()]);
                        }
                    }
                }

                $ok++;
            } catch (Throwable $e) {
                Log::error('firebase-import: listing', ['id' => $fbId, 'e' => $e->getMessage()]);
                if ($firstErrorMessage === null) {
                    $firstErrorMessage = $e->getMessage();
                }
                $line("Listing {$fbId}: {$e->getMessage()}");
                $err++;
            }
        }

        if (! $dryRun) {
            $this->state->save();
        }

        if ($err > 0 && $firstErrorMessage !== null) {
            $line('Hint: if you reset the database but kept storage/app/firebase-import-state.json, old category/user IDs in that file break foreign keys. Run: php artisan firebase:import:reset-state --force');
            $line('First error (often repeats): '.$firstErrorMessage);
        }

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    protected function resolveCategoryId(array $d, int $ownerUserId): ?int
    {
        $catFb = $d['mainServiceCategory'] ?? $d['categoryId'] ?? $d['category_id'] ?? null;
        if (is_string($catFb) && $catFb !== '') {
            $cid = $this->state->getCategoryId($catFb);
            if ($cid !== null && ! Category::query()->whereKey($cid)->exists()) {
                $this->state->forgetCategory($catFb);
                $cid = null;
            }
            if ($cid !== null) {
                return $cid;
            }
            $name = $d['mainServiceCategoryDisplayName'] ?? $d['category_name'] ?? $d['categoryName'] ?? 'Imported';
            $name = is_string($name) ? $name : 'Imported';
            $cat = Category::query()->create([
                'user_id' => $ownerUserId,
                'name' => Str::limit($name, 250, ''),
                'parent_id' => null,
            ]);
            $this->state->setCategoryId($catFb, (int) $cat->id);
            $this->state->save();

            return (int) $cat->id;
        }

        $name = $d['category_name'] ?? $d['categoryName'] ?? null;
        if (is_string($name) && $name !== '' && $name !== 'Other') {
            $found = Category::query()->where('name', $name)->orderBy('id')->value('id');

            return $found ? (int) $found : null;
        }

        return null;
    }

    /**
     * @param  callable(string):void  $line
     * @return array{ok:int, skip:int, err:int}
     */
    public function importFavorites(callable $line, bool $dryRun, ?int $limit): array
    {
        $ok = 0;
        $skip = 0;
        $err = 0;
        $n = 0;

        foreach ($this->jsonFilesIn('favorites') as $path) {
            if ($limit !== null && $n >= $limit) {
                break;
            }
            $n++;

            $doc = $this->readExport($path);
            if (! is_array($doc)) {
                $err++;

                continue;
            }
            $d = $doc['data'] ?? null;
            if (! is_array($d)) {
                $err++;

                continue;
            }
            $d = $this->normalizeUtf8Recursive($d);
            if (($d['itemType'] ?? '') !== 'listing') {
                $skip++;

                continue;
            }
            $userFb = isset($d['userId']) ? (string) $d['userId'] : null;
            $itemFb = isset($d['itemId']) ? (string) $d['itemId'] : null;
            if (! $userFb || ! $itemFb) {
                $err++;

                continue;
            }

            $userId = $this->state->getUserId($userFb);
            if ($userId !== null && ! User::query()->whereKey($userId)->exists()) {
                $this->state->forgetUser($userFb);
                $userId = null;
            }
            if ($userId === null) {
                $userId = User::query()->where('provider', 'firebase')->where('provider_id', $userFb)->value('id');
            }

            $listingId = $this->state->getListingId($itemFb);
            if ($listingId !== null && ! Listing::query()->whereKey($listingId)->exists()) {
                $this->state->forgetListing($itemFb);
                $listingId = null;
            }

            if ($userId === null || $listingId === null) {
                $skip++;

                continue;
            }

            if ($dryRun) {
                $ok++;

                continue;
            }

            try {
                $existing = Favorite::withTrashed()
                    ->where('user_id', $userId)
                    ->where('listing_id', $listingId)
                    ->first();
                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                        $ok++;
                    } else {
                        $skip++;
                    }

                    continue;
                }
                Favorite::query()->create([
                    'user_id' => $userId,
                    'listing_id' => $listingId,
                ]);
                $ok++;
            } catch (Throwable $e) {
                Log::warning('firebase-import: favorite', ['e' => $e->getMessage()]);
                $err++;
            }
        }

        return ['ok' => $ok, 'skip' => $skip, 'err' => $err];
    }

    /**
     * @param  callable(string):void  $line
     * @return array{ok:int, skip:int, err:int}
     */
    public function importAppSettings(callable $line, bool $dryRun): array
    {
        $path = $this->exportsRoot().DIRECTORY_SEPARATOR.'app_config'.DIRECTORY_SEPARATOR.'version_and_maintenance.json';
        if (! is_file($path)) {
            $line('app_config/version_and_maintenance.json not found');

            return ['ok' => 0, 'skip' => 1, 'err' => 0];
        }

        $doc = $this->readExport($path);
        if (! is_array($doc)) {
            return ['ok' => 0, 'skip' => 0, 'err' => 1];
        }
        $d = $doc['data'] ?? null;
        if (! is_array($d)) {
            return ['ok' => 0, 'skip' => 0, 'err' => 1];
        }
        $d = $this->normalizeUtf8Recursive($d);

        $maintenance = (bool) ($d['maintenance_mode'] ?? false);
        $force = (bool) ($d['force_update_below_min'] ?? false);

        if ($dryRun) {
            return ['ok' => 1, 'skip' => 0, 'err' => 0];
        }

        $row = AppSetting::query()->orderBy('id')->first();
        if ($row) {
            $row->update([
                'maintenance_mode' => $maintenance,
                'force_update' => $force,
                'normal_update' => $force ? false : $row->normal_update,
            ]);
        } else {
            AppSetting::query()->create([
                'maintenance_mode' => $maintenance,
                'normal_update' => false,
                'force_update' => $force,
            ]);
        }

        return ['ok' => 1, 'skip' => 0, 'err' => 0];
    }
}
