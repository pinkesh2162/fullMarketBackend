<?php

namespace App\Repositories;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

class CategoryRepository
{
    /**
     * @var Category
     */
    protected $model;

    /**
     * ClaimRepository constructor.
     *
     * @param  Category  $model
     */
    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    /**
     * @return AnonymousResourceCollection
     */
    public function getCategory()
    {
        $categories = $this->model->with(['subCategories.subCategories', 'media'])
            ->whereNull('parent_id')
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', auth('sanctum')->id());
            })->get();

        return CategoryResource::collection($categories);
    }

    /**
     * @return AnonymousResourceCollection
     */
    public function getMainCategory()
    {
        $categories = $this->model->with('media')
            ->whereNull('parent_id')
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', auth('sanctum')->id());
            })
            ->get();

        return CategoryResource::collection($categories);
    }

    /**
     * @param $request
     *
     * @return CategoryResource
     */
    public function store($request)
    {
        $category = $this->model->create([
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

        return CategoryResource::make($category);
    }
}
