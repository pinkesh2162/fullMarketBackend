<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing categories to avoid duplicates during development
        Category::query()->delete();

        $categories = [
            [
                'name' => 'Home Services',
                'image' => 'https://img.icons8.com/color/96/gears.png',
                'sub_categories' => [
                    [
                        'name' => 'House cleaning (hourly or recurring)',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-brush h-5 w-5 shrink-0 text-red-500"><path d="m9.06 11.9 8.07-8.06a2.85 2.85 0 1 1 4.03 4.03l-8.06 8.08"></path><path d="M7.07 14.94c-1.66 0-3 1.35-3 3.02 0 1.33-2.5 1.52-2 2.02 1.08 1.1 2.49 2.02 4 2.02 2.2 0 4-1.8 4-4.04a3.01 3.01 0 0 0-3-3.02z"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Laundry & ironing',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-shirt h-5 w-5 shrink-0 text-red-500"><path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Furniture assembly / disassembly',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-package h-5 w-5 shrink-0 text-red-500"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Handyman services',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-wrench h-5 w-5 shrink-0 text-red-500"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Interior painting',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-paintbrush h-5 w-5 shrink-0 text-red-500"><path d="m14.622 17.897-10.68-2.913"></path><path d="M18.376 2.622a1 1 0 1 1 3.002 3.002L17.36 9.643a.5.5 0 0 0 0 .707l.944.944a2.41 2.41 0 0 1 0 3.408l-.944.944a.5.5 0 0 1-.707 0L8.354 7.348a.5.5 0 0 1 0-.707l.944-.944a2.41 2.41 0 0 1 3.408 0l.944.944a.5.5 0 0 0 .707 0z"></path><path d="M9 8c-1.804 2.71-3.97 3.46-6.583 3.948a.507.507 0 0 0-.302.819l7.32 8.883a1 1 0 0 0 1.185.204C12.735 20.405 16 16.792 16 15"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Gardening & plant care',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-flower2 lucide-flower-2 h-5 w-5 shrink-0 text-red-500"><path d="M12 5a3 3 0 1 1 3 3m-3-3a3 3 0 1 0-3 3m3-3v1M9 8a3 3 0 1 0 3 3M9 8h1m5 0a3 3 0 1 1-3 3m3-3h-1m-2 3v-1"></path><circle cx="12" cy="8" r="2"></circle><path d="M12 10v12"></path><path d="M12 22c4.2 0 7-1.667 7-5-4.2 0-7 1.667-7 5Z"></path><path d="M12 22c-4.2 0-7-1.667-7-5 4.2 0 7 1.667 7 5Z"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Upholstery cleaning',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-brush h-5 w-5 shrink-0 text-red-500"><path d="m9.06 11.9 8.07-8.06a2.85 2.85 0 1 1 4.03 4.03l-8.06 8.08"></path><path d="M7.07 14.94c-1.66 0-3 1.35-3 3.02 0 1.33-2.5 1.52-2 2.02 1.08 1.1 2.49 2.02 4 2.02 2.2 0 4-1.8 4-4.04a3.01 3.01 0 0 0-3-3.02z"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Small moving services',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-package h-5 w-5 shrink-0 text-red-500"><path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"></path><path d="M12 22V12"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><path d="m7.5 4.27 9 5.15"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                    [
                        'name' => 'Patio / outdoor cleaning',
                        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sparkles h-5 w-5 shrink-0 text-red-500"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"></path><path d="M20 3v4"></path><path d="M22 5h-4"></path><path d="M4 17v2"></path><path d="M5 18H3"></path></svg>',
                        'image' => 'https://img.icons8.com/color/96/gears.png',
                    ],
                ],
            ],
            [
                'name' => 'Popular Categories',
                'icon' => 'rtcl-icon-star',
                'image' => 'https://img.icons8.com/color/96/star--v1.png',
                'sub_categories' => [
                    [
                        'name' => 'Vehicles',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/star--v1.png',
                    ],
                    [
                        'name' => 'Rentals',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/star--v1.png',
                    ],
                    [
                        'name' => 'Furniture',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/star--v1.png',
                    ],
                    [
                        'name' => 'Electronics',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/star--v1.png',
                    ],
                    [
                        'name' => 'Women\'s Clothing',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/star--v1.png',
                    ],
                    [
                        'name' => 'Men\'s Clothing',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/star--v1.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/star--v1.png',
                    ],
                ],
            ],
            [
                'name' => 'Vehicles',
                'icon' => 'rtcl-icon-vehicle',
                'image' => 'https://img.icons8.com/color/96/traffic-jam.png',
                'sub_categories' => [
                    [
                        'name' => 'Cars',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/traffic-jam.png',
                    ],
                    [
                        'name' => 'Motorcycles',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/traffic-jam.png',
                    ],
                    [
                        'name' => 'Bicycles',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/traffic-jam.png',
                    ],
                    [
                        'name' => 'Auto Parts',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/traffic-jam.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/traffic-jam.png',
                    ],
                ],
            ],
            [
                'name' => 'Home & Garden',
                'icon' => 'rtcl-icon-home',
                'image' => 'https://img.icons8.com/color/96/home.png',
                'sub_categories' => [
                    [
                        'name' => 'Furniture',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/home.png',
                    ],
                    [
                        'name' => 'Appliances',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/home.png',
                    ],
                    [
                        'name' => 'Tools',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/home.png',
                    ],
                    [
                        'name' => 'Gardening',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/home.png',
                    ],
                    [
                        'name' => 'Home & Kitchen',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/home.png',
                    ],
                    [
                        'name' => 'Patio & Garden',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/home.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/home.png',
                    ],
                ],
            ],
            [
                'name' => 'Clothing & Accessories',
                'icon' => 'rtcl-icon-tshirt',
                'image' => 'https://img.icons8.com/color/96/clothes.png',
                'sub_categories' => [
                    [
                        'name' => 'Women\'s Clothing & Footwear',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/clothes.png',
                    ],
                    [
                        'name' => 'Men\'s Clothing & Footwear',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/clothes.png',
                    ],
                    [
                        'name' => 'Kids & Baby Clothing',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/clothes.png',
                    ],
                    [
                        'name' => 'Jewelry & Accessories',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/clothes.png',
                    ],
                    [
                        'name' => 'Bags & Luggage',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/clothes.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/clothes.png',
                    ],
                ],
            ],
            [
                'name' => 'Family',
                'icon' => 'rtcl-icon-users',
                'image' => 'https://img.icons8.com/color/96/family--v1.png',
                'sub_categories' => [
                    [
                        'name' => 'Babies & Kids',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/family--v1.png',
                    ],
                    [
                        'name' => 'Toys & Games',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/family--v1.png',
                    ],
                    [
                        'name' => 'Pet Products',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/family--v1.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/family--v1.png',
                    ],
                ],
            ],
            [
                'name' => 'Electronics',
                'icon' => 'rtcl-icon-desktop',
                'image' => 'https://img.icons8.com/color/96/imac.png',
                'sub_categories' => [
                    [
                        'name' => 'Mobile Phones',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/imac.png',
                    ],
                    [
                        'name' => 'Electronics & Computers',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/imac.png',
                    ],
                    [
                        'name' => 'Video Games',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/imac.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/imac.png',
                    ],
                ],
            ],
            [
                'name' => 'Hobbies & Entertainment',
                'icon' => 'rtcl-icon-music',
                'image' => 'https://img.icons8.com/color/96/music--v1.png',
                'sub_categories' => [
                    [
                        'name' => 'Books, Movies & Music',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/music--v1.png',
                    ],
                    [
                        'name' => 'Musical Instruments',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/music--v1.png',
                    ],
                    [
                        'name' => 'Arts & Crafts',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/music--v1.png',
                    ],
                    [
                        'name' => 'Sports & Outdoor Activities',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/music--v1.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/music--v1.png',
                    ],
                ],
            ],
            [
                'name' => 'Classifieds',
                'icon' => 'rtcl-icon-news',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sub_categories' => [
                    [
                        'name' => 'Garage Sales',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/news.png',
                    ],
                    [
                        'name' => 'Free Items',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/news.png',
                    ],
                    [
                        'name' => 'Miscellaneous',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/news.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/news.png',
                    ],
                ],
            ],
            [
                'name' => 'Antiques & Collectibles',
                'icon' => 'rtcl-icon-diamond',
                'image' => 'https://img.icons8.com/color/96/diamond--v1.png',
                'sub_categories' => [
                    [
                        'name' => 'Antiques',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/diamond--v1.png',
                    ],
                    [
                        'name' => 'Collectibles',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/diamond--v1.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/diamond--v1.png',
                    ],
                ],
            ],
            [
                'name' => 'Real Estate',
                'icon' => 'rtcl-icon-building',
                'image' => 'https://img.icons8.com/color/96/real-estate.png',
                'sub_categories' => [
                    [
                        'name' => 'Properties for Sale',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/real-estate.png',
                    ],
                    [
                        'name' => 'Rentals',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/real-estate.png',
                    ],
                    [
                        'name' => 'Other (specify)',
                        'icon' => 'rtcl-icon-folder',
                        'image' => 'https://img.icons8.com/color/96/real-estate.png',
                    ],
                ],
            ],
            [
                'name' => 'Electronics & Technology',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 1,
            ],
            [
                'name' => 'Clothing, Footwear & Accessories',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 2,
            ],
            [
                'name' => 'Home & Decor',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 3,
            ],
            [
                'name' => 'Kitchen & Appliances',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 4,
            ],
            [
                'name' => 'Tools & Hardware',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 5,
            ],
            [
                'name' => 'Cars, Motorcycles & Accessories',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 6,
            ],
            [
                'name' => 'Beauty & Personal Care',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 7,
            ],
            [
                'name' => 'Toys & Baby',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 8,
            ],
            [
                'name' => 'Pets & Accessories',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 9,
            ],
            [
                'name' => 'Video Games & Entertainment',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 10,
            ],
            [
                'name' => 'Sports & Fitness',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 11,
            ],
            [
                'name' => 'Books, Stationery & Office',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 12,
            ],
            [
                'name' => 'Cleaning & Organization',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 13,
            ],
            [
                'name' => 'Garden & Outdoor',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 14,
            ],
            [
                'name' => 'Gifts & Novelty Items',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 15,
            ],
            [
                'name' => 'Other item (Add custom category)',
                'type' => 'article',
                'image' => 'https://img.icons8.com/color/96/news.png',
                'sortOrder' => 16,
            ],
        ];

        foreach ($categories as $catData) {
            $parent = Category::create([
                'name' => $catData['name'],
                'user_id' => null,
            ]);

            // Handle parent image
            if (!empty($catData['image'])) {
                try {
                    $parent->addMediaFromUrl($catData['image'])
                        ->toMediaCollection(Category::CATEGORY_IMAGE, config('app.media_disc', 'public'));
                } catch (\Exception $e) {
                    Log::error("Failed to add image for category {$parent->name}: " . $e->getMessage());
                }
            }

            if (!empty($catData['sub_categories'])) {
                foreach ($catData['sub_categories'] as $subData) {
                    $child = Category::create([
                        'name' => $subData['name'],
                        'parent_id' => $parent->id,
                        'user_id' => null,
                    ]);

                    // Prioritize image URL, then fallback to icon SVG
                    if (!empty($subData['image'])) {
                        try {
                            $child->addMediaFromUrl($subData['image'])
                                ->toMediaCollection(Category::CATEGORY_IMAGE, config('app.media_disc', 'public'));
                        } catch (\Exception $e) {
                            Log::error("Failed to add image for sub-category {$child->name}: " . $e->getMessage());
                        }
                    } elseif (!empty($subData['icon']) && str_starts_with($subData['icon'], '<svg')) {
                        try {
                            $child->addMediaFromString($subData['icon'])
                                ->usingFileName('icon.svg')
                                ->toMediaCollection(Category::CATEGORY_IMAGE, config('app.media_disc', 'public'));
                        } catch (\Exception $e) {
                            Log::error("Failed to add icon for sub-category {$child->name}: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
}
