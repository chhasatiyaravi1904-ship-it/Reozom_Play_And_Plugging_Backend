<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(PackageSeeder::class);
        $this->call(StateSeeder::class);
        $this->call(CountySeeder::class);
        $this->call(CitySeeder::class);
        $this->call(ZipCodeSeeder::class);
        $this->call(ListingProcessSeeder::class);
    }
}
