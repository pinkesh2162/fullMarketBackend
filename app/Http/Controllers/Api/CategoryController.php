<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories.
     */
    public function index(): JsonResponse
    {
        $categories = Category::with('subCategories.subCategories')
            ->whereNull('parent_id')->get();
        
        return $this->actionSuccess('Categories fetched successfully', CategoryResource::collection($categories));
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create([
            'user_id' => auth()->id() ?? null,
            'name' => $request->name,
            'parent_id' => @$request->parent_id ?? null,
        ]);

        if (isset($request->image) && $request->image instanceof \Illuminate\Http\UploadedFile) {
            $category->addMedia($request->image)->toMediaCollection(Category::CATEGORY_IMAGE,
                config('app.media_disc', 'public'));
        }

        $category->load('subCategories');

        return $this->actionSuccess('Category created successfully', new CategoryResource($category), self::HTTP_CREATED);
    }
}
