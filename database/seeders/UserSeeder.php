<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's default users, one per role.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Admin User',
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
                'role' => \App\Enums\UserRole::Admin,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
            [
                'name' => 'Agent User',
                'first_name' => 'Agent',
                'last_name' => 'User',
                'email' => 'agent@example.com',
                'password' => Hash::make('password'),
                'role' => \App\Enums\UserRole::Agent,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
                'company' => 'Reozom Premier Realty',
                'office_number' => '248-555-0199',
            ],
            [
                'name' => 'Agent Gmail User',
                'first_name' => 'Agent',
                'last_name' => 'Smith',
                'email' => 'agent@gmail.com',
                'password' => Hash::make('password'),
                'role' => \App\Enums\UserRole::Agent,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
                'company' => 'Reozom Michigan Agents',
                'office_number' => '313-555-0144',
            ],
            [
                'name' => 'Seller User',
                'first_name' => 'Seller',
                'last_name' => 'User',
                'email' => 'seller@example.com',
                'password' => Hash::make('password'),
                'role' => \App\Enums\UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
            [
                'name' => 'Seller Gmail User',
                'first_name' => 'Seller',
                'last_name' => 'Johnson',
                'email' => 'seller@gmail.com',
                'password' => Hash::make('password'),
                'role' => \App\Enums\UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
            [
                'name' => 'Buyer User',
                'first_name' => 'Buyer',
                'last_name' => 'User',
                'email' => 'buyer@example.com',
                'password' => Hash::make('password'),
                'role' => \App\Enums\UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }

        $this->call(SellerBuyerUserSeeder::class);
    }
}
