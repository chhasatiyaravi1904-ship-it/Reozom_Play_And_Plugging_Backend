<?php

namespace Database\Factories;

use App\Models\County;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<County>
 */
class CountyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city().' County';

        return [
            'state_id' => State::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => fake()->unique()->numerify('###'),
            'is_active' => true,
        ];
    }
}
