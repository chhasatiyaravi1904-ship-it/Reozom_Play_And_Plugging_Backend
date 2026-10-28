<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingStatusHistory;
use App\Models\User;
use App\Services\ListingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ListingStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected User $admin;
    protected Listing $listing;
    protected ListingStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        
        $this->listing = Listing::create([
            'user_id' => $this->seller->id,
            'status' => 'draft',
            'address' => '123 Main St',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33101',
        ]);

        $this->service = app(ListingStatusService::class);
    }

    public function test_valid_state_transitions_record_history(): void
    {
        // draft -> in_progress
        $this->service->transition($this->listing, 'in_progress', $this->seller, 'seller', 'Started wizard');
        $this->assertEquals('in_progress', $this->listing->fresh()->status);

        // in_progress -> submitted
        $this->service->transition($this->listing, 'submitted', $this->seller, 'seller', 'Submitted for review');
        $this->assertEquals('submitted', $this->listing->fresh()->status);

        // Check history records
        $history = ListingStatusHistory::where('listing_id', $this->listing->id)->get();
        $this->assertCount(2, $history);
        $this->assertEquals('draft', $history[0]->from_status);
        $this->assertEquals('in_progress', $history[0]->to_status);
        $this->assertEquals('seller', $history[0]->actor_role);
    }

    public function test_invalid_transition_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);

        // Cannot jump directly from draft to sold
        $this->service->transition($this->listing, 'sold', $this->seller, 'seller');
    }

    public function test_admin_force_override_allows_transition(): void
    {
        $updated = $this->service->transition(
            $this->listing,
            'sold',
            $this->admin,
            'admin',
            'Admin closed off-market',
            null,
            true // forceAdminOverride
        );

        $this->assertEquals('sold', $updated->status);
    }

    public function test_status_history_api_endpoint(): void
    {
        $this->service->transition($this->listing, 'in_progress', $this->seller, 'seller');

        $response = $this->actingAs($this->seller)->getJson("/api/listings/{$this->listing->id}/status-history");
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'listing_id',
            'current_status',
            'history' => [
                '*' => ['id', 'from_status', 'to_status', 'actor_role', 'created_at'],
            ],
        ]);
    }
}
