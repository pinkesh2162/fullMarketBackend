<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firestore JSON export root
    |--------------------------------------------------------------------------
    |
    | Path to the folder that contains collection subfolders (admins, listings,
    | categories, favorites, …). Each document is a separate .json file.
    |
    */
    'exports_path' => env(
        'FIREBASE_EXPORTS_PATH',
        base_path('FirebaseDatabase/exports/firestore')
    ),

    /*
    |--------------------------------------------------------------------------
    | Import state file
    |--------------------------------------------------------------------------
    |
    | Maps Firestore document IDs to local MySQL IDs so you can re-run steps
    | without duplicating rows. Delete this file for a clean re-import.
    |
    */
    'state_path' => storage_path('app/firebase-import-state.json'),

    /*
    |--------------------------------------------------------------------------
    | Media disk
    |--------------------------------------------------------------------------
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Default password for imported Firebase users
    |--------------------------------------------------------------------------
    |
    | Applied on create. Use --update-passwords on firebase:import:users to set
    | this for existing firebase provider accounts as well.
    |
    */
    'default_password' => env('FIREBASE_IMPORT_DEFAULT_PASSWORD', '$2y$12$V4bA5J/7xk1mmqun6o.FQ.LAz8s6mSRQ/aU7JC3VvITUgdSdKQVYe'),

    /*
    |--------------------------------------------------------------------------
    | Firestore users collection folder name
    |--------------------------------------------------------------------------
    |
    | Subfolder under exports_path containing one JSON file per user document
    | (same format as other collections: __id + data). Defaults to "users".
    |
    */
    'users_collection' => env('FIREBASE_USERS_COLLECTION', 'users'),

];
