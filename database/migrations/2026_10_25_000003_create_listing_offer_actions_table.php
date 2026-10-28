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
        Schema::create('listing_offer_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_offer_id')->constrained('listing_offers')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32); // buyer, seller, agent, admin, system
            $table->string('action_type', 32); // submitted, counter, accepted, rejected, withdrawn, expired
            $table->decimal('offer_amount', 12, 2)->nullable();
            $table->dateTime('deadline')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_offer_actions');
    }
};
