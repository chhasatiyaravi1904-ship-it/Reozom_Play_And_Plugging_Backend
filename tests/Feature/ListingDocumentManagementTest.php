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

    public function test_seller_can_rename_uploaded_document(): void
    {
        $document = ListingDocument::create([
            'listing_id' => $this->listing->id,
            'user_id' => $this->seller->id,
            'type' => 'survey',
            'original_name' => 'old_survey.pdf',
            'path' => "listings/{$this->listing->id}/documents/survey.pdf",
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'status' => 'uploaded',
        ]);

        $response = $this->actingAs($this->seller)->putJson("/api/listings/{$this->listing->id}/documents/{$document->id}/rename", [
            'name' => 'Boundary_Survey_2026.pdf',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Boundary_Survey_2026.pdf', $document->fresh()->original_name);
    }

    public function test_seller_can_send_document_to_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        Storage::disk('local')->put("listings/{$this->listing->id}/documents/deed.pdf", 'dummy pdf content');

        $document = ListingDocument::create([
            'listing_id' => $this->listing->id,
            'user_id' => $this->seller->id,
            'type' => 'deed',
            'original_name' => 'Warranty_Deed.pdf',
            'path' => "listings/{$this->listing->id}/documents/deed.pdf",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'uploaded',
        ]);

        $response = $this->actingAs($this->seller)->postJson("/api/listings/{$this->listing->id}/documents/{$document->id}/send-email", [
            'emails' => ['buyer@example.com', 'title_company@example.com'],
            'note' => 'Please find attached the signed deed.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.document', 'Warranty_Deed.pdf');
    }
}
