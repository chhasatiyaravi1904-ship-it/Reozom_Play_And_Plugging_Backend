<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\County;
use App\Models\ZipCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZipCode>
 */
class ZipCodeFactory extends Factory
{
    protected $model = ZipCode::class;

    public function definition(): array
    {
        $county = County::factory()->create();
        $city = City::factory()->create(['county_id' => $county->id]);

        return [
            'code' => fake()->unique()->numerify('#####'),
            'state_id' => $county->state_id,
            'county_id' => $county->id,
            'city_id' => $city->id,
            'is_active' => true,
        ];
    }
}
