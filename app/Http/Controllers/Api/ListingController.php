<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Http\Requests\StoreListingRequest;
use App\Http\Requests\UpdateListingRequest;
use App\Http\Resources\ListingResource;
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
        $filters = $request->only(['category', 'location', 'lat', 'long', 'radius']);
        
        $listings = $this->listingRepo->getListings($filters, $perPage);
        
        return $this->actionSuccess('Listings fetched successfully', ListingResource::collection($listings));
    }
    
    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function getMyListing(Request $request): JsonResponse
    {
        $perPage = @$request->perPage ?? 15;
        
        $listings = $this->listingRepo->getMyListings($perPage);
        
        return $this->actionSuccess('Listings fetched successfully', ListingResource::collection($listings));
    }
    
    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function getFeaturedListings(Request $request): JsonResponse
    {
        $perPage = @$request->perPage ?? 3;
        $filters = $request->only(['category', 'location', 'lat', 'long', 'radius']);
        
        $listings = $this->listingRepo->getFeaturedListings($filters, $perPage);
        
        return $this->actionSuccess('Featured listings fetched successfully', ListingResource::collection($listings));
    }
    

    /**
     * @param  StoreListingRequest  $request
     *
     * @return JsonResponse
     */
    public function store(StoreListingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['user_id'] = auth()->id();

        $this->listingRepo->createListing($data, $request->file('images'));

        return $this->actionSuccess('Listing created successfully');
    }

    public function show(Listing $listing): JsonResponse
    {
        $listing->load(['user', 'store', 'category']);
        return $this->actionSuccess('Listing fetched successfully', new ListingResource($listing));
    }

    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        if ($listing->user_id !== auth()->id()) {
            return $this->forbidden('You can only update your own listing.');
        }

        $listing = $this->listingRepo->updateListing($listing, $request->validated(), $request->file('images'));
        return $this->actionSuccess('Listing updated successfully', new ListingResource($listing));
    }

    public function destroy(Listing $listing): JsonResponse
    {
        if ($listing->user_id !== auth()->id()) {
            return $this->forbidden('You can only delete your own listing.');
        }

        $this->listingRepo->deleteListing($listing);
        return $this->actionSuccess('Listing deleted successfully');
    }
}
