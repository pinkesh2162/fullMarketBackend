<?php

return [

    /*
    | Related listings (parity with mobile Firebase getRelatedListings).
    | See ListingRepository::getRelatedListings.
    */
    'related' => [
        /** Default page size for GET …/listings/{id}/related (infinite scroll). */
        'per_page' => 10,
        /** Upper bound for ?perPage= / limit (keeps slice math stable across pages). */
        'max_per_page' => 24,
        'radius_km' => 50,
        /** Max items in the ranked pool (all pages slice from this ordered list). */
        'max_pool' => 96,
        'min_before_fallback' => 12,
        /** Per query branch: same category in-country, then same category elsewhere (merged, deduped). */
        'category_limit' => 150,
        /** Other listings from the same store (any category), merged into the pool before geo. */
        'store_limit' => 80,
        'geo_limit' => 100,
        'name_anchor_scan' => 220,
        'global_fallback_fetch' => 500,
        'global_fallback_take' => 100,
        /** When true, recency uses created_at (published); else updated_at. */
        'order_by_published_at' => false,
    ],

];
