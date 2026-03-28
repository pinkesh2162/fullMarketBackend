<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

class LargeListingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $totalToCreate = 50000; // Default count, can be increased to 100000
        $chunkSize = 1000;

        $this->command->info("Starting to generate {$totalToCreate} listings...");

        // Ensure we have some users and categories first
        $users = User::count() < 10 ? User::factory()->count(10)->create() : User::all();
        $categories = Category::count() < 10 ? Category::factory()->count(10)->create() : Category::all();
        $stores = Store::count() < 10 ? Store::factory()->count(10)->create() : Store::all();

        for ($i = 0; $i < $totalToCreate; $i += $chunkSize) {
            $currentChunk = min($chunkSize, $totalToCreate - $i);
            
            Listing::factory()
                ->count($currentChunk)
                ->state(function (array $attributes) use ($users, $categories, $stores) {
                    $user = $users->random();
                    $store = $stores->where('user_id', $user->id)->first() ?? Store::factory()->create(['user_id' => $user->id]);
                    return [
                        'user_id' => $user->id,
                        'service_category' => $categories->random()->id,
                        'store_id' => $store->id,
                    ];
                })
                ->create();

            $this->command->info("Created " . ($i + $currentChunk) . " listings...");
        }

        $this->command->info("Completed generating {$totalToCreate} listings.");
    }
}
