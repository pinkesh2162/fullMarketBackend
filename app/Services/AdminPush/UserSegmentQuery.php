<?php

namespace App\Services\AdminPush;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserSegmentQuery
{
    public const SEGMENTS = [
        'all',
        'android',
        'ios',
        'newUsers',
        'inactive',
        'withStore',
        'withoutStore',
        'featuredListings',
    ];

    public static function segmentLabel(string $segmentId): string
    {
        return match ($segmentId) {
            'all' => 'All users',
            'android' => 'Android',
            'ios' => 'iOS',
            'newUsers' => 'New users',
            'inactive' => 'Inactive users',
            'withStore' => 'Users with a store',
            'withoutStore' => 'Users without a store',
            'featuredListings' => 'Users with featured listings',
            default => $segmentId,
        };
    }

    /**
     * @param  array{segmentId: string, countryFilter: string, cityFilter: string}  $filters
     */
    public function baseQuery(array $filters): Builder
    {
        $q = User::query()->whereNull('deleted_at');

        $segmentId = $filters['segmentId'];
        $newDays = (int) config('admin_push.new_user_days', 30);
        $inactiveDays = (int) config('admin_push.inactive_days', 90);
        $minViews = (int) config('admin_push.featured_listing_min_views', 25);

        switch ($segmentId) {
            case 'all':
                break;
            case 'android':
                $q->where(function (Builder $w) {
                    $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.platform\'))) = ?', ['android'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.device_platform\'))) = ?', ['android'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.deviceType\'))) = ?', ['android']);
                });
                break;
            case 'ios':
                $q->where(function (Builder $w) {
                    $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.platform\'))) in (?, ?)', ['ios', 'iphone'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.device_platform\'))) = ?', ['ios'])
                        ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.deviceType\'))) in (?, ?)', ['ios', 'iphone']);
                });
                break;
            case 'newUsers':
                $q->where('created_at', '>=', now()->subDays($newDays));
                break;
            case 'inactive':
                $q->where('updated_at', '<', now()->subDays($inactiveDays));
                break;
            case 'withStore':
                $q->whereHas('store');
                break;
            case 'withoutStore':
                $q->whereDoesntHave('store');
                break;
            case 'featuredListings':
                $q->whereHas('listings', function (Builder $lq) use ($minViews) {
                    $lq->whereNull('deleted_at')->where('views_count', '>=', $minViews);
                });
                break;
        }

        $country = trim($filters['countryFilter'] ?? 'all');
        $city = trim($filters['cityFilter'] ?? 'all');

        if ($country !== '' && $country !== 'all') {
            $countryLike = '%'.$country.'%';
            $q->where(function (Builder $w) use ($countryLike) {
                $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(location, \'$.country\'))) like LOWER(?)', [$countryLike])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(location, \'$.address\'))) like LOWER(?)', [$countryLike]);
            });
        }

        if ($city !== '' && $city !== 'all') {
            $cityLike = '%'.$city.'%';
            $q->where(function (Builder $w) use ($cityLike) {
                $w->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(location, \'$.city\'))) like LOWER(?)', [$cityLike])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(location, \'$.address\'))) like LOWER(?)', [$cityLike]);
            });
        }

        return $q;
    }
}
