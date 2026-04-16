<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User segment thresholds
    |--------------------------------------------------------------------------
    */
    'new_user_days' => (int) env('ADMIN_PUSH_NEW_USER_DAYS', 30),

    'inactive_days' => (int) env('ADMIN_PUSH_INACTIVE_DAYS', 90),

    /** Minimum listing views_count to qualify for the "featuredListings" segment. */
    'featured_listing_min_views' => (int) env('ADMIN_PUSH_FEATURED_MIN_VIEWS', 25),

    /*
    |--------------------------------------------------------------------------
    | FCM HTTP v1 concurrency (parallel single-message requests per chunk)
    |--------------------------------------------------------------------------
    */
    'fcm_concurrency' => (int) env('ADMIN_PUSH_FCM_CONCURRENCY', 40),

    'user_query_chunk' => (int) env('ADMIN_PUSH_USER_CHUNK', 500),

    /*
    |--------------------------------------------------------------------------
    | Firestore collection names
    |--------------------------------------------------------------------------
    */
    'collection_campaigns' => env('ADMIN_PUSH_FIRESTORE_CAMPAIGNS', 'notification_campaigns'),

    'collection_admin_actions' => env('ADMIN_PUSH_FIRESTORE_ADMIN_ACTIONS', 'admin_actions'),

    'collection_admins' => env('ADMIN_PUSH_FIRESTORE_ADMINS', 'admins'),

    /*
    |--------------------------------------------------------------------------
    | Idempotency: reclaim stuck "processing" campaigns after N minutes
    |--------------------------------------------------------------------------
    */
    'processing_stale_minutes' => (int) env('ADMIN_PUSH_PROCESSING_STALE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Accept Firebase ID token in JSON body (local / Postman only)
    |--------------------------------------------------------------------------
    |
    | When true, middleware also reads "firebase_id_token" from the request body
    | if the Authorization header is missing or not a JWT. Do not enable in
    | production unless you understand the trade-offs (tokens may appear in logs).
    |
    */
    'accept_firebase_id_token_in_body' => (bool) env('ADMIN_PUSH_ACCEPT_FIREBASE_TOKEN_BODY', false),

];
