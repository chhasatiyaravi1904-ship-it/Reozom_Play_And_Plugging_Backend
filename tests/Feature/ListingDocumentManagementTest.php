<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingDocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected User $admin;
    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->listing = Listing::create([
            'user_id' => $this->seller->id,
            'status' => 'in_progress',
            'address' => '789 Palm Way',
            'city' => 'Tampa',
            'state' => 'FL',
            'zip' => '33602',
        ]);
    }

    public function test_document_upload_sets_pending_review_status(): void
    {
        $file = UploadedFile::fake()->create('deed.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->seller)->postJson("/api/listings/{$this->listing->id}/documents", [
            'document_id' => 'deed',
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.verificationStatus', 'pending_review');

        $this->assertDatabaseHas('listing_documents', [
            'listing_id' => $this->listing->id,
            'type' => 'deed',
            'status' => 'pending_review',
        ]);
    }

    public function test_admin_can_review_and_approve_document(): void
    {
        $document = ListingDocument::create([
            'listing_id' => $this->listing->id,
            'user_id' => $this->seller->id,
            'type' => 'deed',
            'original_name' => 'deed.pdf',
            'path' => "listings/{$this->listing->id}/documents/test.pdf",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'pending_review',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/listings/{$this->listing->id}/documents/deed/review", [
            'status' => 'approved',
            'admin_notes' => 'Ownership verified with county clerk.',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('approved', $document->fresh()->status);
        $this->assertEquals('Ownership verified with county clerk.', $document->fresh()->admin_notes);
        $this->assertEquals($this->admin->id, $document->fresh()->reviewed_by);
    }
}
