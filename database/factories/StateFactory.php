<?php

namespace Database\Factories;

use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<State>
 */
class StateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->state();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'code' => fake()->unique()->stateAbbr(),
            'is_active' => true,
        ];
    }
}
