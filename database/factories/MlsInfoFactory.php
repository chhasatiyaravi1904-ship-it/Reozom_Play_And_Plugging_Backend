<?php

namespace Database\Factories;

use App\Models\MlsDirectory;
use App\Models\MlsInfo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MlsInfo>
 */
class MlsInfoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mls_directory_id' => MlsDirectory::factory(),
            'title' => fake()->sentence(3),
            'countries' => [fake()->country()],
            'public_websites_title' => fake()->sentence(4),
            'websites' => [fake()->url()],
            'info' => fake()->paragraph(),
        ];
    }
}
