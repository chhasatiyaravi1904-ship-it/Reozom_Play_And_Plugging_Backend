<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document templates per state and property type (same document sets as the original Reozom),
 * the list of documents each listing process generates, and the link from a listing document
 * to the template it was generated from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('state_code', 10)->nullable();
            $table->string('property_type', 50)->nullable();
            // 'disclosure' = rendered from disclosure steps, 'static' = agreement-type document
            $table->string('source', 20)->default('static');
            $table->json('disclosure_codes')->nullable();
            $table->json('rules')->nullable();
            $table->longText('body')->nullable();
            $table->boolean('requires_signature')->default(true);
            $table->integer('order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('listing_processes', function (Blueprint $table) {
            // Enabled template codes; null = every template that matches the listing
            $table->json('documents')->nullable()->after('required_disclosures');
        });

        Schema::table('listing_documents', function (Blueprint $table) {
            $table->foreignId('document_template_id')->nullable()->after('type')
                ->constrained('document_templates')->nullOnDelete();
            $table->longText('content')->nullable()->after('document_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('listing_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_template_id');
            $table->dropColumn('content');
        });

        Schema::table('listing_processes', function (Blueprint $table) {
            $table->dropColumn('documents');
        });

        Schema::dropIfExists('document_templates');
    }
};
