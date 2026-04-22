<?php

namespace App\Repositories\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserRepository
{
    public function paginateIndex(Request $request): LengthAwarePaginator
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $q = User::query()
            ->with(['media'])
            ->withCount([
                'listings' => function ($lq) {
                    $lq->whereNull('deleted_at');
                },
                'stores',
            ])
            ->orderByDesc('id');

        $this->applyStatusFilter($q, (string) $request->query('status', 'all'));
        $this->applySearch($q, (string) $request->query('q', $request->query('search', '')));
        $this->applyCountryFilter($q, (string) $request->query('country', 'all'));
        $this->applyOsFilter($q, (string) $request->query('os', 'all'));

        return $q->paginate($perPage);
    }

    public function findForAdmin(int $id, bool $withTrashed = true): User
    {
        $q = User::query()->with(['media'])->withCount([
            'listings' => function ($lq) {
                $lq->whereNull('deleted_at');
            },
            'stores',
        ]);
        if ($withTrashed) {
            $q->withTrashed();
        }

        return $q->whereKey($id)->firstOrFail();
    }

    /**
     * @return list<array{code: string, count: int}>
     */
    public function distinctCountryOptions(): array
    {
        if (DB::getDriverName() === 'mysql') {
            $rows = DB::table('users')
                ->whereNull('deleted_at')
                ->whereNotNull('location')
                ->selectRaw("COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.country')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.countryName')), ''),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(location, '$.country_name')), '')
                ) as c")
                ->get();

            $counts = [];
            foreach ($rows as $r) {
                if (empty($r->c) || ! is_string($r->c)) {
                    continue;
                }
                $k = $r->c;
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
            ksort($counts, SORT_NATURAL);
            $out = [];
            foreach ($counts as $code => $count) {
                $out[] = ['code' => $code, 'count' => (int) $count];
            }

            return $out;
        }

        $counts = [];
        User::query()->whereNull('deleted_at')->orderBy('id')
            ->chunk(500, function ($chunk) use (&$counts) {
                foreach ($chunk as $u) {
                    $loc = is_array($u->location) ? $u->location : [];
                    $c = $loc['country'] ?? $loc['countryName'] ?? $loc['country_name'] ?? null;
                    if (is_string($c) && $c !== '') {
                        $counts[$c] = ($counts[$c] ?? 0) + 1;
                    }
                }
            });
        ksort($counts, SORT_NATURAL);
        $out = [];
        foreach ($counts as $code => $count) {
            $out[] = ['code' => $code, 'count' => (int) $count];
        }

        return $out;
    }

    public function createFromRequest(\App\Http\Requests\Admin\StoreAdminUserRequest $request): User
    {
        $name = (string) $request->input('name', '');
        if ($name !== '' && ! $request->filled('first_name')) {
            $parts = preg_split('/\s+/u', trim($name), 2, PREG_SPLIT_NO_EMPTY) ?: [];
            $first = $parts[0] ?? 'User';
            $last = $parts[1] ?? null;
        } else {
            $first = (string) $request->input('first_name');
            $last = $request->input('last_name');
        }

        $user = User::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => (string) $request->input('email'),
            'password' => (string) $request->input('password'),
            'phone' => $request->input('phone'),
            'phone_code' => $request->input('phone_code'),
            // 'account_status' => $request->input('account_status', User::ACCOUNT_STATUS_ACTIVE),
            'unique_key' => $this->generateUniqueUserKey(),
            'email_verified_at' => now(),
        ]);
        $user->load(['media'])->loadCount([
            'listings' => function ($lq) {
                $lq->whereNull('deleted_at');
            },
            'stores',
        ]);

        return $user;
    }

    public function updateFromRequest(User $user, \App\Http\Requests\Admin\UpdateAdminUserRequest $request): User
    {
        $data = $request->validated();
        if (! empty($data['name']) && ! $request->filled('first_name') && ! $request->filled('last_name')) {
            $parts = preg_split('/\s+/u', trim((string) $data['name']), 2, PREG_SPLIT_NO_EMPTY) ?: [];
            $data['first_name'] = $parts[0] ?? $user->first_name;
            $data['last_name'] = $parts[1] ?? $user->last_name;
        }
        unset($data['name'], $data['profile_photo']);
        if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
            // users.password is cast to hashed on the model
        } else {
            unset($data['password']);
        }
        if (isset($data['account_status']) && $data['account_status'] === null) {
            unset($data['account_status']);
        }
        if ($user->trashed()) {
            $user->restore();
        }
        $user->update($data);

        if ($request->hasFile('profile_photo')) {
            $user->clearMediaCollection(User::PROFILE);
            $user->addMedia($request->file('profile_photo'))->toMediaCollection(User::PROFILE, config('app.media_disc', 'public'));
        }

        $user->load(['media'])->loadCount([
            'listings' => function ($lq) {
                $lq->whereNull('deleted_at');
            },
            'stores',
        ]);

        return $user->fresh();
    }

    public function softDelete(User $user): void
    {
        $user->delete();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $q
     */
    private function applyStatusFilter($q, string $status): void
    {
        $status = strtolower($status) ?: 'all';
        if ($status === 'deleted') {
            $q->onlyTrashed();

            return;
        }
        $q->whereNull('users.deleted_at');
        if ($status === 'all' || $status === '') {
            return;
        }
        if (in_array($status, [User::ACCOUNT_STATUS_ACTIVE, User::ACCOUNT_STATUS_SUSPEND, User::ACCOUNT_STATUS_BLOCKED], true)) {
            $q->where('users.account_status', $status);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $q
     */
    private function applySearch($q, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $q->where(function ($w) use ($term, $like) {
            $w->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like);
            if (ctype_digit($term)) {
                $w->orWhere('id', (int) $term)
                    ->orWhere('unique_key', 'like', $like);
            } else {
                $digits = preg_replace('/\D+/', '', $term);
                if (strlen($digits) >= 4) {
                    $w->orWhere('unique_key', 'like', '%'.$digits.'%');
                }
            }
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $q
     */
    private function applyCountryFilter($q, string $country): void
    {
        $country = trim($country);
        if ($country === '' || $country === 'all') {
            return;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $country).'%';
        $q->where(function ($w) use ($like) {
            $w->where('location->country', 'like', $like)
                ->orWhere('location->countryName', 'like', $like)
                ->orWhere('location->country_name', 'like', $like);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $q
     */
    private function applyOsFilter($q, string $os): void
    {
        $os = strtolower(trim($os));
        if ($os === '' || $os === 'all') {
            return;
        }
        if ($os === 'android') {
            if (DB::getDriverName() === 'mysql') {
                $q->where(function ($w) {
                    $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.platform\'))) = ?', ['android'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.device_platform\'))) = ?', ['android'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.deviceType\'))) = ?', ['android']);
                });
            } else {
                $q->where(function ($w) {
                    $w->whereRaw('lower(json_extract(data, ?)) = ?', ['$.platform', 'android'])
                        ->orWhereRaw('lower(json_extract(data, ?)) = ?', ['$.device_platform', 'android'])
                        ->orWhereRaw('lower(json_extract(data, ?)) = ?', ['$.deviceType', 'android']);
                });
            }

            return;
        }
        if ($os === 'ios') {
            if (DB::getDriverName() === 'mysql') {
                $q->where(function ($w) {
                    $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.platform\'))) in (?, ?)', ['ios', 'iphone'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.device_platform\'))) = ?', ['ios'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.deviceType\'))) in (?, ?)', ['ios', 'iphone']);
                });
            } else {
                $q->where(function ($w) {
                    $w->whereRaw("lower(json_extract(data, '$.platform')) in ('ios','iphone')")
                        ->orWhereRaw("lower(json_extract(data, '$.device_platform')) = 'ios'")
                        ->orWhereRaw("lower(json_extract(data, '$.deviceType')) in ('ios','iphone')");
                });
            }
        }
    }

    private function generateUniqueUserKey(): string
    {
        do {
            $code = (string) random_int(1000000, 9999999);
        } while (User::withTrashed()->where('unique_key', $code)->exists());

        return $code;
    }
}
