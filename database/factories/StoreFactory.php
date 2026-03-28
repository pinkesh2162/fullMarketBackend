<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Store>
 */
class StoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'location' => [
                'address' => fake()->address(),
                'lat' => fake()->latitude(),
                'long' => fake()->longitude(),
            ],
            'business_time' => [
                ['day' => 'monday', 'start' => '09:00', 'end' => '17:00'],
                ['day' => 'tuesday', 'start' => '09:00', 'end' => '17:00'],
                ['day' => 'wednesday', 'start' => '09:00', 'end' => '17:00'],
                ['day' => 'thursday', 'start' => '09:00', 'end' => '17:00'],
                ['day' => 'friday', 'start' => '09:00', 'end' => '17:00'],
            ],
            'contact_information' => [
                'phone1' => ['code' => '+1', 'number' => fake()->phoneNumber()],
                'email' => fake()->safeEmail(),
            ],
            'social_media' => [
                'facebook' => 'https://facebook.com/' . fake()->userName(),
                'instagram' => 'https://instagram.com/' . fake()->userName(),
            ],
        ];
    }
}
