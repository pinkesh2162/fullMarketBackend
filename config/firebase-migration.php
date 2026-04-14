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

    'json_decode_max_depth' => 512,

];
