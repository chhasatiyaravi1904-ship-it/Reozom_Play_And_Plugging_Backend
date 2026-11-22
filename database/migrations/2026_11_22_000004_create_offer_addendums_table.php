<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addendum/Amendment to an accepted purchase agreement (original Reozom `addendum` table),
 * signed by the purchase agreement signers through EunoDoc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_addendums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_offer_id')->constrained('listing_offers')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            $table->foreignId('listing_euno_agreement_id')->nullable()->constrained('listing_euno_agreements')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_addendums');
    }
};
