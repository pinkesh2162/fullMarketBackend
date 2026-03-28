<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $serviceType = fake()->randomElement([
            Listing::OFFER_SERVICE,
            Listing::ARTICLE_FOR_SALE,
            Listing::PROPERTY_FOR_SALE,
            Listing::VEHICLE_FOR_SALE,
        ]);

        $data = [
            'user_id' => User::factory(),
            'store_id' => Store::factory(),
            'service_type' => $serviceType,
            'title' => fake()->sentence(),
            'service_category' => Category::factory(),
            'description' => fake()->paragraph(),
            'search_keyword' => implode(', ', fake()->words(3)),
            'currency' => 'USD',
            'price' => fake()->numberBetween(100, 10000),
            'additional_info' => [
                'location' => [
                    'address' => fake()->address(),
                    'lat' => fake()->latitude(),
                    'long' => fake()->longitude(),
                ],
                'show_approximate_location' => true,
                'claim_ad' => true,
            ],
        ];

        if ($serviceType === Listing::PROPERTY_FOR_SALE) {
            $data['listing_type'] = fake()->randomElement([Listing::FOR_SALE, Listing::FOR_RENT]);
            $data['property_type'] = fake()->randomElement(['House', 'Apartment', 'Studio']);
            $data['bedrooms'] = fake()->numberBetween(1, 5);
            $data['bathrooms'] = fake()->numberBetween(1, 4);
            $data['advance_options'] = [
                'square_meters' => fake()->numberBetween(50, 500),
                'total_surface' => fake()->numberBetween(60, 1000),
                'parking_type' => fake()->randomElement(['street', 'private', 'none']),
                'laundry_type' => fake()->randomElement(['shared', 'private']),
                'additional_info' => [
                    'amenities' => fake()->randomElements(['Bicycle', 'Basement', 'Cable TV', 'Balcony'], fake()->numberBetween(1, 4)),
                ],
            ];
        }

        if ($serviceType === Listing::OFFER_SERVICE) {
            $data['service_modality'] = fake()->randomElement(['At Home', 'Online', 'In Store']);
            $data['contact_info'] = [
                'phone1' => ['code' => '+1', 'number' => fake()->phoneNumber()],
                'email' => fake()->safeEmail(),
            ];
            $data['additional_info']['is_working_hour'] = true;
            $data['additional_info']['schedule'] = [
                ['day' => 'sunday', 'start' => '09:00', 'end' => '17:00'],
            ];
        }

        if ($serviceType === Listing::ARTICLE_FOR_SALE) {
            $data['availability'] = Listing::AVAILABLE;
            $data['condition'] = fake()->randomElement(['new', 'used']);
            $data['contact_info'] = ['chat_enable' => true];
        }

        if ($serviceType === Listing::VEHICLE_FOR_SALE) {
            $data['vehicle_type'] = fake()->randomElement(['car', 'motorcycle', 'truck']);
            $data['vehical_info'] = [
                'brand' => fake()->company(),
                'model' => fake()->word(),
                'year' => fake()->year(),
                'milage' => fake()->numberBetween(0, 200000),
                'no_of_owners' => fake()->numberBetween(1, 3),
            ];
            $data['fual_type'] = fake()->randomElement(['petrol', 'diesel', 'electric']);
            $data['transmission'] = fake()->randomElement(['automatic', 'manual']);
        }

        return $data;
    }
}
