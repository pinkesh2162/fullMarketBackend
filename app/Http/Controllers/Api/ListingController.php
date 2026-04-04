<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Http\Resources\FeatureListingResource;
use App\Http\Resources\FrontListingResource;
use App\Http\Resources\ListingResource;
use App\Models\Favorite;
use App\Repositories\ListingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    /**
     * @var ListingRepository
     */
    protected ListingRepository $listingRepo;

    /**
     * ListingController constructor.
     * @param  ListingRepository  $listingRepository
     */
    public function __construct(ListingRepository $listingRepository)
    {
        $this->listingRepo = $listingRepository;
    }

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = @$request->perPage ?? 15;

        $filters = $request->only([
            'category',
            'location',
            'lat',
            'long',
            'lng',
            'radius',
            'hide_ads',
            'title',
            'search_keyword',
            'store_id'
        ]);

        if (!isset($filters['long']) && isset($filters['lng'])) {
            $filters['long'] = $filters['lng'];
        }

        $listings = $this->listingRepo->getListings($filters, $perPage);

        return $this->actionSuccess(
            'listings_fetched',
            FrontListingResource::collection($listings),
            self::HTTP_OK,
            $this->customizingResponseData($listings)['pagination']
        );
    }

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function getMyListing(Request $request): JsonResponse
    {
        $perPage = @$request->perPage ?? 15;

        $filters = $request->only(['category', 'location', 'lat', 'long', 'lng', 'radius', 'hide_ads', 'title', 'search_keyword']);
        if (!isset($filters['long']) && isset($filters['lng'])) {
            $filters['long'] = $filters['lng'];
        }

        $listings = $this->listingRepo->getMyListings($filters, $perPage);

        return $this->actionSuccess(
            'listings_fetched',
            ListingResource::collection($listings),
            self::HTTP_OK,
            $this->customizingResponseData($listings)['pagination']
        );
    }

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function getFeaturedListings(Request $request): JsonResponse
    {
        $perPage = @$request->perPage ?? 3;
        $filters = $request->only(['category', 'location', 'lat', 'long', 'lng', 'radius', 'hide_ads', 'title', 'search_keyword']);
        if (!isset($filters['long']) && isset($filters['lng'])) {
            $filters['long'] = $filters['lng'];
        }

        $listings = $this->listingRepo->getFeaturedListings($filters, $perPage);

        return $this->actionSuccess(
            'featured_listings_fetched',
            FeatureListingResource::collection($listings),
            self::HTTP_OK,
            $this->customizingResponseData($listings)['pagination']
        );
    }


    /**
     * @param  StoreListingRequest  $request
     *
     * @return JsonResponse
     */
    public function store(StoreListingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->listingRepo->createListing($data, $request->file('images'));

        return $this->actionSuccess('listing_created');
    }

    public function show($id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        if ($listing->user_id !== auth()->id()) {
            $this->listingRepo->incrementViews($listing);
        }
        $listing->with(['user', 'store', 'category']);

        return $this->actionSuccess('listing_fetched', new ListingResource($listing));
    }

    public function update(UpdateListingRequest $request, $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        if ((int) $listing->user_id !== auth()->id()) {
            return $this->forbidden('listing_update_unauthorized');
        }

        $listing = $this->listingRepo->updateListing($listing, $request->all(), $request->file('images'));
        return $this->actionSuccess('listing_updated', new ListingResource($listing));
    }

    public function destroy($id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        if ((int) $listing->user_id !== auth()->id()) {
            return $this->forbidden('listing_delete_unauthorized');
        }

        $this->listingRepo->deleteListing($listing);
        return $this->actionSuccess('listing_deleted');
    }

    /**
     * @param  Listing  $listing
     * @param  Request  $request
     * @return JsonResponse
     */
    public function getRelatedListings(Listing $listing, Request $request): JsonResponse
    {
        $perPage = @$request->perPage ?? 6;
        $listings = $this->listingRepo->getRelatedListings($listing, $perPage);

        return $this->actionSuccess('related_listings_fetched', ListingResource::collection($listings));
    }

    public function getCount(Request $request): JsonResponse
    {
        $listingCount = Listing::toBase()
            ->where('user_id', auth('sanctum')->id())
            ->count();
        $favoriteCount = Favorite::toBase()
            ->where('user_id', auth('sanctum')->id())
            ->count();

        return $this->actionSuccess('listing_count', [
            'listing_count' => $listingCount,
            'favorite_count' => $favoriteCount,
        ]);
    }
}
