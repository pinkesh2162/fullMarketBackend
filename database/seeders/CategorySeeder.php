<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $json = file_get_contents(database_path('seeders/categories.json'));
        $categories = json_decode($json, true);

        foreach ($categories as $catData) {
            $parent = Category::updateOrCreate(
                ['name' => $catData['title'], 'parent_id' => null],
                ['user_id' => null]
            );

            if (!empty($catData['lists'])) {
                foreach ($catData['lists'] as $subData) {
                    $child = Category::updateOrCreate(
                        ['name' => $subData['title'], 'parent_id' => $parent->id],
                        ['user_id' => null]
                    );

                    if (!empty($subData['icon']) && str_starts_with($subData['icon'], '<svg')) {
                        // Check if media already exists to avoid duplicates
                        if ($child->getMedia(Category::CATEGORY_IMAGE)->isEmpty()) {
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
}
