<?php

namespace Tests\Feature;

use App\Models\AgentPackage;
use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\ZipCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicWebsiteSearchTest extends TestCase
{
    use RefreshDatabase;

    private function subscribedAgent(bool $active = true, bool $expired = false): User
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => $active]);
        $package = Package::firstOrCreate(['slug' => 'agent-pro'], [
            'name' => 'Agent Pro', 'role_name' => 'agent-pro', 'price' => 50,
            'duration_days' => 30, 'is_active' => true,
        ]);
        AgentPackage::create([
            'user_id' => $agent->id,
            'package_id' => $package->id,
            'started_at' => now()->subDays(40),
            'expires_at' => $expired ? now()->subDay() : now()->addDays(30),
        ]);

        return $agent;
    }

    private function packageFor(User $agent, array $zips, bool $active = true): ServicePackage
    {
        $pkg = ServicePackage::factory()->create(['agent_id' => $agent->id, 'is_active' => $active]);
        $ids = collect($zips)->map(fn ($code) => ZipCode::firstOrCreate(
            ['code' => $code],
            ZipCode::factory()->make(['code' => $code, 'is_active' => true])->getAttributes()
        )->id);
        $pkg->zipCodes()->sync($ids);

        return $pkg;
    }

    private function publishedListing(string $type, string $price): Listing
    {
        $listing = Listing::factory()->create(['status' => 'published']);
        ListingAnswer::create([
            'listing_id' => $listing->id,
            'step_id' => ListingProcess::LEGACY_PUBLIC_STEP_ID,
            'values' => ['property_type' => $type, 'listing_price' => $price],
        ]);

        return $listing;
    }

    public function test_packages_without_zip_return_paginated_defaults(): void
    {
        $agent = $this->subscribedAgent();
        foreach (range(1, 8) as $i) {
            $this->packageFor($agent, ['4830' . $i]);
        }

        $this->getJson('/api/public/service-packages?per_page=6')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('meta.total', 8)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/public/service-packages?per_page=6&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_packages_match_zip_prefix(): void
    {
        $agent = $this->subscribedAgent();
        $this->packageFor($agent, ['48301']);
        $this->packageFor($agent, ['48399']);
        $this->packageFor($agent, ['78701']);

        $this->getJson('/api/public/service-packages?zip=483')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/public/service-packages?zip=48301')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/public/service-packages?zip=9')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_packages_exclude_unavailable_agents_and_packages(): void
    {
        $this->packageFor($this->subscribedAgent(active: false), ['48301']);
        $this->packageFor($this->subscribedAgent(expired: true), ['48301']);
        $this->packageFor($this->subscribedAgent(), ['48301'], active: false);

        $this->getJson('/api/public/service-packages?zip=48301')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_package_response_does_not_expose_private_fields(): void
    {
        $this->packageFor($this->subscribedAgent(), ['48301']);

        $item = $this->getJson('/api/public/service-packages')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('email', $item['agent']);
        $this->assertArrayNotHasKey('stripe_sync', $item);
        $this->assertSame(['48301'], $item['zip_codes']);
    }

    public function test_package_zip_must_be_digits(): void
    {
        $this->getJson('/api/public/service-packages?zip=48%25')->assertStatus(422);
    }

    // --- Exact-ZIP search used by the seller's "Create listing" page ---

    public function test_exact_zip_search_hides_private_fields_and_shows_the_plan_process(): void
    {
        $pkg = $this->packageFor($this->subscribedAgent(), ['48301']);
        $process = ListingProcess::create([
            'name' => 'Agent Custom Process', 'type' => 'custom', 'status' => 'active',
            'agent_id' => $pkg->agent_id,
            'config' => [['id' => 's1', 'name' => 'Address'], ['id' => 's2', 'name' => 'Payment']],
        ]);
        $pkg->update(['listing_process_id' => $process->id]);

        $item = $this->getJson('/api/public/service-packages/search?zipcode=48301')
            ->assertOk()->assertJsonCount(1)->json('0');

        $this->assertArrayNotHasKey('email', $item['agent']);
        foreach (['stripe_sync', 'stripe_product_id', 'stripe_price_id', 'listing_process_id'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
        $this->assertSame('Agent Custom Process', $item['listing_process']['name']);
        $this->assertSame(['Address', 'Payment'], $item['listing_process']['steps']);
    }

    public function test_exact_zip_search_falls_back_to_the_system_default_process(): void
    {
        $this->packageFor($this->subscribedAgent(), ['48301']);
        ListingProcess::create([
            'name' => 'Residential Property Listing Process', 'type' => 'default', 'status' => 'active',
            'agent_id' => null, 'config' => [['id' => 's1', 'name' => 'Property Type & Address']],
        ]);

        $this->getJson('/api/public/service-packages/search?zipcode=48301')
            ->assertOk()
            ->assertJsonPath('0.listing_process.name', 'Residential Property Listing Process')
            ->assertJsonPath('0.listing_process.steps', ['Property Type & Address']);
    }

    public function test_exact_zip_search_returns_null_process_when_none_resolves(): void
    {
        $this->packageFor($this->subscribedAgent(), ['48301']);

        $this->getJson('/api/public/service-packages/search?zipcode=48301')
            ->assertOk()
            ->assertJsonPath('0.listing_process', null);
    }

    public function test_listings_filter_by_property_type_and_price(): void
    {
        $house = $this->publishedListing('single_family', '$450,000');
        $condo = $this->publishedListing('condo', '300000');
        $this->publishedListing('single_family', '900000');

        $this->getJson('/api/public/listings?property_type=condo')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $condo->id);

        $this->getJson('/api/public/listings?property_type=single_family&min_price=400000&max_price=500000')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $house->id);

        $this->getJson('/api/public/listings?property_type=all')->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_listings_price_filter_paginates(): void
    {
        foreach (range(1, 5) as $i) {
            $this->publishedListing('condo', (string) (100000 * $i));
        }

        $this->getJson('/api/public/listings?min_price=200000&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_listing_filters_endpoint_returns_existing_types_and_price_range(): void
    {
        $this->publishedListing('condo', '300000');
        $this->publishedListing('single_family', '$900,000');
        Listing::factory()->create(['status' => 'draft']);

        $this->getJson('/api/public/listings/filters')
            ->assertOk()
            ->assertJsonPath('data.property_types', [
                ['value' => 'condo', 'label' => 'Condominium'],
                ['value' => 'single_family', 'label' => 'Single Family Home'],
            ])
            ->assertJsonPath('data.price.min', 300000)
            ->assertJsonPath('data.price.max', 900000);
    }
}
