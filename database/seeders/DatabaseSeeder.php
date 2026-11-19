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
        $this->call(SellerBuyerUserSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(PackageSeeder::class);
        $this->call(AgentUserSeeder::class);
        $this->call(MlsSeeder::class);
        $this->call(StateSeeder::class);
        $this->call(CountySeeder::class);
        $this->call(CitySeeder::class);
        $this->call(ZipCodeSeeder::class);
        // Disclosures first: state listing processes copy their questions into disclosure steps
        $this->call(ReozomStatutoryDisclosuresSeeder::class);
        $this->call(DocumentTemplateSeeder::class);
        $this->call(ListingProcessSeeder::class);
        $this->call(MichiganListingProcessSeeder::class);
        $this->call(FloridaListingProcessSeeder::class);
        $this->call(PublishedListingSeeder::class);
        $this->call(ReozomFullWorkflowSeeder::class);
        $this->call(StateSampleListingSeeder::class);
    }
}
