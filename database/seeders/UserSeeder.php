<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's default users, one per role.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        User::factory()->agent()->create([
            'name' => 'Agent User',
            'email' => 'agent@example.com',
        ]);

        User::factory()->seller()->create([
            'name' => 'Seller User',
            'email' => 'seller@example.com',
        ]);

        User::factory()->buyer()->create([
            'name' => 'Buyer User',
            'email' => 'buyer@example.com',
        ]);
    }
}
