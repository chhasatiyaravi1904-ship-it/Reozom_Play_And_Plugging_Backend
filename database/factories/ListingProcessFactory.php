<?php

namespace Database\Factories;

use App\Models\ListingProcess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingProcess>
 */
class ListingProcessFactory extends Factory
{
    protected $model = ListingProcess::class;

    public function definition(): array
    {
        return [
            'agent_id' => User::factory()->agent(),
            'name' => fake()->words(3, true),
            'status' => 'active',
            'type' => 'custom',
            'config' => [
                ['id' => 'step1', 'name' => 'Step 1'],
                ['id' => 'step2', 'name' => 'Step 2'],
            ],
        ];
    }
}
