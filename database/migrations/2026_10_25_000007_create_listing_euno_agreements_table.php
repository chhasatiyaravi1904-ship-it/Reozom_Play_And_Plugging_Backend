<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('listing_euno_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('listing_offer_id')->nullable()->constrained('listing_offers')->nullOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('document_type', 100)->default('disclosures_bundle')->index();
            $table->string('document_name')->default('Reozom Documents');
            
            // EunoDoc Agreement identifiers
            $table->string('agreement_id', 150)->nullable()->index(); // MongoDB _id from Eunodoc
            $table->text('signing_url')->nullable();
            
            // Status: draft, inprogress, completed, canceled, rejected
            $table->string('status', 50)->default('draft')->index();
            
            // Signers array (email, name, role, recipient_id, has_signed, signed_at)
            $table->json('signers')->nullable();
            
            // Raw coordinate mappings placed on the PDFs
            $table->json('coordinates')->nullable();
            
            // Paths to source and resulting signed files
            $table->json('source_file_paths')->nullable();
            $table->string('signed_file_path')->nullable();
            $table->string('audit_file_path')->nullable();
            
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_euno_agreements');
    }
};
