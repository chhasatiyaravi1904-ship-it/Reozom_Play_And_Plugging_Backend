<?php

namespace Database\Factories;

use App\Models\MlsDirectory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MlsDirectory>
 */
class MlsDirectoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->company().' MLS',
        ];
    }
}
