<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each user's own document library (original Reozom AllDocument / "Manage Documents"): files
 * uploaded outside a listing, and signed documents shared with the user (e.g. an addendum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Set for documents shared from a listing (e.g. a signed addendum)
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            // upload | addendum
            $table->string('source', 32)->default('upload');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_documents');
    }
};
