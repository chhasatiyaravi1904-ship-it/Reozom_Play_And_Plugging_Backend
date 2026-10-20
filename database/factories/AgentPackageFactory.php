<?php

namespace Database\Factories;

use App\Models\AgentPackage;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentPackageFactory extends Factory
{
    protected $model = AgentPackage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'package_id' => Package::factory(),
            'started_at' => now(),
            'expires_at' => now()->addDays(30),
        ];
    }
}
