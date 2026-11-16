<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SellerBuyerUserSeeder extends Seeder
{
    /**
     * Run the database seeds to generate 5 Seller / Buyer users.
     */
    public function run(): void
    {
        $sellerBuyers = [
            [
                'name' => 'David Miller',
                'first_name' => 'David',
                'last_name' => 'Miller',
                'email' => 'david.miller@example.com',
                'phone' => '(248) 555-0111',
                'street_address' => '150 West Congress St',
                'city' => 'Detroit',
                'state' => 'MI',
                'zip' => '48226',
                'password' => Hash::make('password'),
                'role' => UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
            [
                'name' => 'Sarah Jenkins',
                'first_name' => 'Sarah',
                'last_name' => 'Jenkins',
                'email' => 'sarah.jenkins@example.com',
                'phone' => '(734) 555-0122',
                'street_address' => '812 South State St',
                'city' => 'Ann Arbor',
                'state' => 'MI',
                'zip' => '48104',
                'password' => Hash::make('password'),
                'role' => UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
            [
                'name' => 'Michael Chang',
                'first_name' => 'Michael',
                'last_name' => 'Chang',
                'email' => 'michael.chang@example.com',
                'phone' => '(616) 555-0133',
                'street_address' => '450 Heritage Hill Way',
                'city' => 'Grand Rapids',
                'state' => 'MI',
                'zip' => '49503',
                'password' => Hash::make('password'),
                'role' => UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
            [
                'name' => 'Emily Rodriguez',
                'first_name' => 'Emily',
                'last_name' => 'Rodriguez',
                'email' => 'emily.rodriguez@example.com',
                'phone' => '(248) 555-0144',
                'street_address' => '3250 West Big Beaver Rd',
                'city' => 'Troy',
                'state' => 'MI',
                'zip' => '48084',
                'password' => Hash::make('password'),
                'role' => UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
            [
                'name' => 'Robert Taylor',
                'first_name' => 'Robert',
                'last_name' => 'Taylor',
                'email' => 'robert.taylor@example.com',
                'phone' => '(313) 555-0155',
                'street_address' => '1600 Michigan Ave',
                'city' => 'Dearborn',
                'state' => 'MI',
                'zip' => '48126',
                'password' => Hash::make('password'),
                'role' => UserRole::SellerBuyer,
                'email_verified_at' => now(),
                'is_active' => true,
                'profile_finished' => true,
            ],
        ];

        foreach ($sellerBuyers as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }
    }
}
