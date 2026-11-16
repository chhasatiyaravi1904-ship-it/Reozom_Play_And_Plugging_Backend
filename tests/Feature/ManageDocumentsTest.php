<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManageDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected User $otherAgent;

    protected User $seller;

    protected User $otherSeller;

    protected Listing $agentListing;

    protected Listing $otherListing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentTemplateSeeder::class);

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->agent = User::factory()->create(['role' => UserRole::Agent]);
        $this->otherAgent = User::factory()->create(['role' => UserRole::Agent]);
        $this->seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $this->otherSeller = User::factory()->create(['role' => UserRole::SellerBuyer]);

        $package = ServicePackage::create(['agent_id' => $this->agent->id, 'name' => 'FL Package', 'price' => 500, 'is_active' => true]);
        $otherProcess = ListingProcess::factory()->create(['agent_id' => $this->otherAgent->id]);

        $this->agentListing = Listing::factory()->create([
            'user_id' => $this->seller->id, 'state' => 'FL', 'address' => '1 Ocean Drive', 'reference_code' => 'REO-AAA111',
            'service_package_id' => $package->id,
        ]);
        $this->otherListing = Listing::factory()->create([
            'user_id' => $this->otherSeller->id, 'state' => 'MI', 'address' => '9 Lake Road', 'reference_code' => 'REO-BBB222',
            'listing_process_id' => $otherProcess->id,
        ]);

        $this->doc($this->agentListing, 'deed', 'Deed.pdf', 'uploaded');
        $this->doc($this->agentListing, 'fl_res_listing_agreement', 'Listing Agreement.pdf', 'unsigned', 'fl_res_listing_agreement');
        $this->doc($this->otherListing, 'id_proof', 'Driver License.pdf', 'uploaded');
    }

    private function doc(Listing $listing, string $type, string $name, string $status, ?string $templateCode = null): ListingDocument
    {
        return ListingDocument::create([
            'listing_id' => $listing->id,
            'user_id' => $listing->user_id,
            'type' => $type,
            'document_template_id' => $templateCode ? \App\Models\DocumentTemplate::where('code', $templateCode)->value('id') : null,
            'original_name' => $name,
            'path' => "listings/{$listing->id}/{$type}.pdf",
            'mime_type' => 'application/pdf',
            'size' => 1000,
            'status' => $status,
        ]);
    }

    private function names(User $user, array $query = []): array
    {
        return collect($this->actingAs($user)->getJson('/api/documents?'.http_build_query($query))
            ->assertOk()->json('data.items'))->pluck('name')->sort()->values()->all();
    }

    public function test_admin_sees_all_documents(): void
    {
        $this->assertSame(['Deed', 'Driver License', 'Listing Agreement'], $this->names($this->admin));
    }

    public function test_seller_sees_only_their_own_listing_documents(): void
    {
        $this->assertSame(['Deed', 'Listing Agreement'], $this->names($this->seller));
        $this->assertSame(['Driver License'], $this->names($this->otherSeller));
    }

    public function test_agent_sees_listings_under_their_packages_or_processes(): void
    {
        $this->assertSame(['Deed', 'Listing Agreement'], $this->names($this->agent));
        $this->assertSame(['Driver License'], $this->names($this->otherAgent));
    }

    public function test_filters_by_kind_state_and_search(): void
    {
        $this->assertSame(['Listing Agreement'], $this->names($this->admin, ['kind' => 'generated']));
        $this->assertSame(['Deed', 'Driver License'], $this->names($this->admin, ['kind' => 'uploaded']));
        $this->assertSame(['Driver License'], $this->names($this->admin, ['state' => 'MI']));
        $this->assertSame(['Deed', 'Listing Agreement'], $this->names($this->admin, ['search' => 'Ocean']));
        $this->assertSame(['Driver License'], $this->names($this->admin, ['search' => 'REO-BBB']));
    }

    public function test_generated_documents_cannot_be_managed_or_deleted(): void
    {
        $items = collect($this->actingAs($this->seller)->getJson('/api/documents')->assertOk()->json('data.items'));
        $this->assertTrue($items->firstWhere('name', 'Deed')['canManage']);
        $this->assertFalse($items->firstWhere('name', 'Listing Agreement')['canManage']);
        $this->assertSame('generated', $items->firstWhere('name', 'Listing Agreement')['kind']);

        $this->actingAs($this->seller)
            ->deleteJson("/api/listings/{$this->agentListing->id}/documents/fl_res_listing_agreement")
            ->assertStatus(422);
        $this->actingAs($this->seller)
            ->deleteJson("/api/listings/{$this->agentListing->id}/documents/deed")
            ->assertOk();
    }

    public function test_guests_cannot_list_documents(): void
    {
        $this->getJson('/api/documents')->assertUnauthorized();
    }
}
