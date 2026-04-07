<?php

/**
 * Fallback images when a category has no Spatie media (category_image collection).
 *
 * - default_image_url / custom_category_image_url: remote source URLs used to populate local files.
 * - public_subdir: PNGs are stored under public/{public_subdir}/ (e.g. home-services.png).
 * - mirror_remote_on_miss: when true, first API hit downloads the PNG once; then all hits use local files.
 * - Run `php artisan categories:download-images` on deploy to prefetch without waiting for traffic.
 *
 * Override via .env: CATEGORY_DEFAULT_IMAGE_URL, CATEGORY_CUSTOM_IMAGE_URL, CATEGORY_MIRROR_REMOTE
 */
return [

    'public_subdir' => 'images/categories',

    'mirror_remote_on_miss' => filter_var(
        env('CATEGORY_MIRROR_REMOTE', true),
        FILTER_VALIDATE_BOOL
    ),

    'default_image_url' => env(
        'CATEGORY_DEFAULT_IMAGE_URL',
        'https://img.icons8.com/color/96/briefcase.png'
    ),

    'custom_category_image_url' => env(
        'CATEGORY_CUSTOM_IMAGE_URL',
        'https://img.icons8.com/color/96/folder-invoices.png'
    ),

    /*
    |--------------------------------------------------------------------------
    | Category name (lowercase) → remote PNG source (Icons8 color 96px)
    |--------------------------------------------------------------------------
    |
    | Includes every parent and subcategory from database/seeders/categories.json so each
    | row has its own icon. Regenerate with: php database/scripts/generate_category_image_map.php
    |
    | Files mirror to public/images/categories/{slug}.png (`php artisan categories:download-images`).
    |
    */
    'images_by_name' => require __DIR__.'/category_image_map.php',

];
