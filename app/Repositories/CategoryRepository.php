<?php

namespace App\Repositories;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Listing;
use Illuminate\Container\Container as Application;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

class CategoryRepository extends BaseRepository
{
    /**
     * @param  Application  $app
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * @return array
     */
    public function getFieldsSearchable()
    {
        return [
            'name',
            'parent_id',
        ];
    }

    /**
     * @return string
     */
    public function model()
    {
        return Category::class;
    }

    /**
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getCategory($perPage = 15)
    {
        //        $version = Cache::get('category_cache_version', 1);
        //        $userId = auth('sanctum')->id() ?? 'guest';
        //        $page = Request::get('page', 1);
        //        $cacheKey = "categories_v{$version}_{$userId}_{$perPage}_{$page}";

        return $this->allQuery()->with([
            'subCategories.parent',
            'subCategories.media',
            'subCategories.subCategories.parent',
            'subCategories.subCategories.media',
            'media',
        ])
            ->whereNull('parent_id')
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', auth('sanctum')->id());
            })->paginate($perPage);
        //        return Cache::remember($cacheKey, now()->addDay(), function () use ($perPage) {
        //            return $this->allQuery()->with(['subCategories.subCategories', 'media'])
        //                ->whereNull('parent_id')
        //                ->where(function ($query) {
        //                    $query->whereNull('user_id')
        //                        ->orWhere('user_id', auth('sanctum')->id());
        //                })->paginate($perPage);
        //        });
    }

    /**
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getMainCategory($perPage = 15)
    {
        //        $version = Cache::get('category_cache_version', 1);
        //        $userId = auth('sanctum')->id() ?? 'guest';
        //        $page = Request::get('page', 1);
        //        $cacheKey = "main_categories_v{$version}_{$userId}_{$perPage}_{$page}";

        return $this->allQuery()->with('media')
            ->whereNull('parent_id')
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', auth('sanctum')->id());
            })
            ->paginate($perPage);

        //        return Cache::remember($cacheKey, now()->addDay(), function () use ($perPage) {
        //            return $this->allQuery()->with('media')
        //                ->whereNull('parent_id')
        //                ->where(function ($query) {
        //                    $query->whereNull('user_id')
        //                        ->orWhere('user_id', auth('sanctum')->id());
        //                })
        //                ->paginate($perPage);
        //        });
    }

    /**
     * @param int $perPage
     */
    public function getMostUsedCategory($perPage = 15)
    {
        $user = auth('sanctum')->user();
        $hideAds = $user?->settings?->hide_ads ?? false;

        // Per–service_category counts (same base constraints as listing browse; SoftDeletes apply).
        $rawCounts = Listing::query()
            ->where(function ($q) {
                $q->where('availability', true)->orWhereNull('availability');
            })
            ->when($hideAds, function ($q) {
                $q->where('service_type', '!=', Listing::OFFER_SERVICE);
            })
            ->whereNotNull('service_category')
            ->selectRaw('service_category, COUNT(*) as aggregate')
            ->groupBy('service_category')
            ->pluck('aggregate', 'service_category');

        $countsByCategoryId = [];
        foreach ($rawCounts as $catId => $n) {
            $countsByCategoryId[(int) $catId] = (int) $n;
        }

        $mainCategories = $this->allQuery()
            ->with('media')
            ->whereNull('parent_id')
            ->where(function ($query) use ($user) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $user?->id);
            })
            ->get();

        foreach ($mainCategories as $category) {
            $ids = Listing::serviceCategoryIdsForFilter((int) $category->id);
            $total = 0;
            foreach ($ids as $cid) {
                $total += $countsByCategoryId[$cid] ?? 0;
            }
            $category->setAttribute('listings_count', $total);
        }

        return $mainCategories->sortByDesc('listings_count')->values();
    }

    /**
     * @param $request
     *
     * @return CategoryResource
     */
    public function store($request)
    {
        $category = $this->create([
            'user_id'   => auth('sanctum')->id() ?? null,
            'name'      => $request->name,
            'parent_id' => $request->parent_id ?? null,
        ]);

        if (isset($request->image) && $request->image instanceof UploadedFile) {
            $category->addMedia($request->image)->toMediaCollection(
                Category::CATEGORY_IMAGE,
                config('app.media_disc', 'public')
            );
        }

        //        $this->clearCategoryCache();

        return CategoryResource::make($category);
    }

    /**
     * Clears all category related caches.
     */
    public function clearCategoryCache(): void
    {
        Cache::increment('category_cache_version');
    }

    /**
     * @param $id
     *
     * @throws \Exception
     */
    public function delete($id)
    {
        $category = $this->model->where('id', $id)->where('user_id', auth('sanctum')->id())->first();
        if (!$category) {
            throw new \Exception('Category not found');
        }
        $category->delete();
        //        $this->clearCategoryCache();
    }
}
