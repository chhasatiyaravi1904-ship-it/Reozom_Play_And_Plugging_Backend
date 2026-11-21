<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Listing;
use App\Models\ListingProcess;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\FloridaListingProcessSeeder;
use Database\Seeders\MichiganListingProcessSeeder;
use Database\Seeders\ReozomStatutoryDisclosuresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only the Michigan and Florida listing processes are seeded (original Reozom), and every one
 * carries the fixed steps: Location on the Map, Pictures & Virtual Tours, and Payment (last).
 */
class ListingFixedStepsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReozomStatutoryDisclosuresSeeder::class);
        $this->seed(DocumentTemplateSeeder::class);
        User::factory()->create(['role' => UserRole::Agent, 'email' => 'agent@example.com']);
        $this->seed(MichiganListingProcessSeeder::class);
        $this->seed(FloridaListingProcessSeeder::class);
    }

    public function test_only_the_michigan_and_florida_processes_are_seeded(): void
    {
        $this->assertSame(
            ['Florida Residential Listing Process', 'Michigan Residential Listing Process'],
            ListingProcess::orderBy('name')->pluck('name')->all()
        );
    }

    public function test_every_process_has_the_fixed_map_pictures_and_payment_steps(): void
    {
        foreach (ListingProcess::all() as $process) {
            $steps = collect($process->config)->keyBy('id');

            foreach ([ListingProcess::MAP_STEP_ID => 'map', ListingProcess::MEDIA_STEP_ID => 'media', ListingProcess::PAYMENT_STEP_ID => 'payment'] as $id => $kind) {
                $this->assertTrue($steps->has($id), "{$process->name} is missing {$id}");
                $this->assertTrue($steps[$id]['locked'], "{$id} must be locked in {$process->name}");
                $this->assertSame($kind, $steps[$id]['kind']);
            }

            // Map right after "Property Type & Address", Payment always last
            $ids = array_column($process->config, 'id');
            $this->assertSame(ListingProcess::MAP_STEP_ID, $ids[array_search('step_property_type_address', $ids, true) + 1]);
            $this->assertSame(ListingProcess::PAYMENT_STEP_ID, end($ids));
        }
    }

    public function test_pictures_step_counts_as_done_only_once_the_listing_has_a_photo(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $process = ListingProcess::where('name', 'Florida Residential Listing Process')->first();
        $listing = Listing::factory()->create([
            'user_id' => $seller->id,
            'listing_process_id' => $process->id,
            'workflow_snapshot' => $process->config,
            'steps_total' => count($process->config),
            'steps_completed' => 0,
        ]);
        $mediaStep = ListingProcess::MEDIA_STEP_ID;

        // Tour link and terms saved, but no photo yet: not complete
        $this->actingAs($seller)->postJson("/api/listings/{$listing->id}/steps/{$mediaStep}", [
            'values' => ['virtual_tour_url' => 'https://tour.example', 'media_terms_agreed' => '1'],
        ])->assertOk();
        // (steps that don't apply to this listing, e.g. the Condo disclosure, already count as done)
        $before = $listing->fresh()->steps_completed;

        // With a photo on the listing the step completes
        $listing->images()->create(['url' => '/storage/listing-images/a.jpg', 'sort_order' => 1, 'is_public' => true, 'is_primary' => true]);
        $this->actingAs($seller)->postJson("/api/listings/{$listing->id}/steps/{$mediaStep}", [
            'values' => ['virtual_tour_url' => 'https://tour.example', 'media_terms_agreed' => '1', 'property_images' => 1],
        ])->assertOk();
        $this->assertSame($before + 1, $listing->fresh()->steps_completed);
    }
}
