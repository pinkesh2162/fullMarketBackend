<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CategoryResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Repositories\CategoryRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * @var
     */
    protected $categoryRepo;

    /**
     * CategoryController constructor.
     * @param  CategoryRepository  $categoryRepo
     */
    public function __construct(CategoryRepository $categoryRepo)
    {
        $this->categoryRepo = $categoryRepo;
    }

    /**
     * Display a listing of categories.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->per_page ?? $request->perPage ?? 15;
        $categories = $this->categoryRepo->getCategory($perPage);

        return $this->actionSuccess(
            'categories_fetched',
            CategoryResource::collection($categories),
            self::HTTP_OK,
            $this->customizingResponseData($categories)['pagination']
        );
    }

    /**
     * Display a listing of main categories.
     */
    public function getMainCategories(Request $request): JsonResponse
    {
        $perPage = $request->per_page ?? $request->perPage ?? 15;
        $categories = $this->categoryRepo->getMainCategory($perPage);

        return $this->actionSuccess(
            'categories_fetched',
            CategoryResource::collection($categories),
            self::HTTP_OK,
            $this->customizingResponseData($categories)['pagination']
        );
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category =  $this->categoryRepo->store($request);

        return $this->actionSuccess('category_created', $category, self::HTTP_CREATED);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->categoryRepo->delete($id);

        return $this->actionSuccess('category_deleted');
    }
}
