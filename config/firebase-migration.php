<?php

return [

    'exports_path' => env(
        'FIREBASE_EXPORTS_PATH',
        base_path('FirebaseDatabase/exports/firestore')
    ),

    'state_path' => env(
        'FIREBASE_MIGRATION_STATE_PATH',
        storage_path('app/firebase-migration-state.json')
    ),

    'log_path' => env(
        'FIREBASE_MIGRATION_LOG_PATH',
        storage_path('logs/firebase-migration.log')
    ),

    'media_disk' => env('MEDIA_DISK', 'public'),

    'users_folder' => env('FIREBASE_MIGRATION_USERS_FOLDER', 'users'),

    'categories_folder' => env('FIREBASE_MIGRATION_CATEGORIES_FOLDER', 'categories'),

    'stores_folder' => env('FIREBASE_MIGRATION_STORES_FOLDER', 'stores'),

    'listings_folder' => env('FIREBASE_MIGRATION_LISTINGS_FOLDER', 'listings'),

    'favorites_folder' => env('FIREBASE_MIGRATION_FAVORITES_FOLDER', 'favorites'),

    'reviews_folder' => env('FIREBASE_MIGRATION_REVIEWS_FOLDER', 'reviews'),

    'search_queries_folder' => env('FIREBASE_MIGRATION_SEARCH_QUERIES_FOLDER', 'search_queries'),

    'store_followers_folder' => env('FIREBASE_MIGRATION_STORE_FOLLOWERS_FOLDER', 'store_followers'),

    'app_config_folder' => env('FIREBASE_MIGRATION_APP_CONFIG_FOLDER', 'app_config'),

    /*
    | Pre-hashed bcrypt for all imported Firebase users (plain-text is never stored).
    */
    'imported_user_password_hash' => env(
        'FIREBASE_MIGRATION_USER_PASSWORD_HASH',
        '$2y$12$V4Ba5J/7Xk1Mmqun6O.Fq.Laz8S6Msrq/Au7Jc3Vvitugdsdkqvye'
    ),

    'sample_limit' => 25,

    'media_download_retries' => 3,

    'media_retry_sleep_ms' => 250,

    /*
    | Base URL used when exported media paths are legacy relative paths
    | such as /public/uploads/... or uploads/...
    */
    'legacy_media_base_url' => env('FIREBASE_MIGRATION_LEGACY_MEDIA_BASE_URL', 'https://fullmarket.net'),

    'json_decode_max_depth' => 512,

];
