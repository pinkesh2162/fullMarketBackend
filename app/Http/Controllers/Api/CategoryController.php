<?php

namespace App\Http\Controllers\Api;

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
        $categories = $this->categoryRepo->getCategory();

        return $this->actionSuccess('categories_fetched', $categories);
    }

    /**
     * Display a listing of main categories.
     */
    public function getMainCategories(): JsonResponse
    {
        $categories = $this->categoryRepo->getMainCategory();

        return $this->actionSuccess('categories_fetched', $categories);
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
