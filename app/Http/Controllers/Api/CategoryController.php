<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::with(['subCategories.subCategories', 'media'])
            ->whereNull('parent_id')
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', auth('sanctum')->id());
            })->get();

        return $this->actionSuccess('categories_fetched', CategoryResource::collection($categories));
    }

    /**
     * Display a listing of categories.
     */
    public function getMainCategories(): JsonResponse
    {
        $userId = auth('sanctum')->id() ?? 'guest';
        $cacheKey = "main_categories_{$userId}";

          $categories =  Category::with('media')
                ->whereNull('parent_id')
                ->where(function ($query) {
                    $query->whereNull('user_id')
                        ->orWhere('user_id', auth('sanctum')->id());
                })
                ->get();

        // $categories = Cache::remember($cacheKey, now()->addDay(), function () {
        //     return Category::with('media')
        //         ->whereNull('parent_id')
        //         ->where(function ($query) {
        //             $query->whereNull('user_id')
        //                 ->orWhere('user_id', auth('sanctum')->id());
        //         })
        //         ->get();
        // });

        return $this->actionSuccess('categories_fetched', CategoryResource::collection($categories));
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
            $category->addMedia($request->image)->toMediaCollection(
                Category::CATEGORY_IMAGE,
                config('app.media_disc', 'public')
            );
        }

        $category->load('subCategories');

        // Cache::forget('main_categories_guest');
        // if (auth()->check()) {
        //     Cache::forget('main_categories_' . auth()->id());
        // }

        return $this->actionSuccess('category_created', new CategoryResource($category), self::HTTP_CREATED);
    }
}
