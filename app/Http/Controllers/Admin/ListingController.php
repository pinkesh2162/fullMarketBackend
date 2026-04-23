<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminListingResource;
use App\Repositories\Admin\AdminListingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function __construct(
        protected AdminListingRepository $listings
    ) {}

    public function summary(): JsonResponse
    {
        return $this->actionSuccess('admin_listings_summary_fetched', $this->listings->summary());
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'nullable|in:all,active,expired,deleted,reported,featured,inactive',
            'category_id' => 'nullable|integer|min:1',
            'category' => 'nullable|integer|min:1',
            'q' => 'nullable|string|max:255',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = $this->listings->paginateIndex($request);

        return $this->actionSuccess(
            'admin_listings_fetched',
            AdminListingResource::collection($paginator->items()),
            self::HTTP_OK,
            $this->customizingResponseData($paginator)['pagination'] ?? null
        );
    }

    public function show(int $id): JsonResponse
    {
        $listing = $this->listings->findForAdmin($id);

        return $this->actionSuccess('admin_listing_fetched', new AdminListingResource($listing));
    }

    public function feature(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'is_featured' => 'required|boolean',
        ]);
        $listing = $this->listings->findForAdmin($id);
        if ($listing->trashed()) {
            return $this->actionFailure('admin_listing_trashed', null, self::HTTP_CONFLICT);
        }
        $listing = $this->listings->setFeatured($listing, (bool) $data['is_featured']);
        $listing->load(['store', 'category', 'user', 'media']);
        $listing->loadCount('reports as reports_count');

        return $this->actionSuccess('admin_listing_feature_updated', new AdminListingResource($listing));
    }

    public function destroy(int $id): JsonResponse
    {
        $listing = $this->listings->findForAdmin($id);
        $this->listings->softDelete($listing);

        return $this->actionSuccess('admin_listing_deleted');
    }
}
