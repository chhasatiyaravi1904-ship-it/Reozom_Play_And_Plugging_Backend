<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingEunoAgreement;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EunoDownloadAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $agent;
    private ListingEunoAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->agent = User::factory()->create(['role' => 'agent']);
        $package = ServicePackage::factory()->create(['agent_id' => $this->agent->id]);
        $listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'service_package_id' => $package->id,
        ]);

        Storage::disk('local')->put('signed/doc.pdf', '%PDF-signed');
        Storage::disk('local')->put('signed/audit.pdf', '%PDF-audit');

        $this->agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id,
            'initiated_by_user_id' => $this->seller->id,
            'status' => 'completed',
            'signed_file_path' => 'signed/doc.pdf',
            'audit_file_path' => 'signed/audit.pdf',
        ]);
    }

    private function urls(): array
    {
        return [
            "/api/euno-agreements/{$this->agreement->id}/signed-doc",
            "/api/euno-agreements/{$this->agreement->id}/audit",
        ];
    }

    public function test_guests_cannot_download_signed_documents(): void
    {
        foreach ($this->urls() as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_unrelated_users_cannot_download_signed_documents(): void
    {
        $stranger = User::factory()->create(['role' => 'seller']);
        $otherAgent = User::factory()->create(['role' => 'agent']);

        foreach ($this->urls() as $url) {
            $this->actingAs($stranger)->getJson($url)->assertForbidden();
            $this->actingAs($otherAgent)->getJson($url)->assertForbidden();
        }
    }

    public function test_owner_agent_and_admin_can_download(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([$this->seller, $this->agent, $admin] as $user) {
            foreach ($this->urls() as $url) {
                $this->actingAs($user)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            }
        }
    }

    public function test_query_token_link_used_by_the_frontend_works(): void
    {
        $token = $this->seller->createToken('test')->plainTextToken;

        $this->get("/api/euno-agreements/{$this->agreement->id}/signed-doc?token=" . urlencode($token))
            ->assertOk();
    }
}
