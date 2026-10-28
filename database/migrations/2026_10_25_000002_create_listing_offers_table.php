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
        Schema::create('listing_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reference_code', 32)->unique();
            $table->string('status', 32)->default('submitted')->index();
            // Statuses: submitted, countered_by_seller, countered_by_buyer, accepted, rejected, withdrawn, expired, rejected_under_contract
            
            // Financial & closing terms
            $table->decimal('offer_amount', 12, 2);
            $table->decimal('earnest_money_deposit', 12, 2)->default(0);
            $table->decimal('down_payment_amount', 12, 2)->nullable();
            $table->string('payment_method', 32)->default('conventional'); // cash, conventional, fha, va, other
            $table->date('closing_date');
            $table->string('occupancy_type', 32)->default('at_closing'); // at_closing, days_after_closing
            $table->integer('occupancy_days')->default(0);
            $table->decimal('seller_concessions', 10, 2)->default(0);
            
            // Contingencies (property inspection, appraisal, financing, sale of home, attorney review)
            $table->json('contingencies')->nullable();
            
            // Expiration deadline for counter or acceptance
            $table->dateTime('deadline')->nullable()->index();
            $table->text('additional_terms')->nullable();
            
            // Backup offer flag (if listing is already under contract)
            $table->boolean('is_backup_offer')->default(false);
            
            // Signer / representation info
            $table->string('buyer_name')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->boolean('has_agent_representation')->default(false);
            $table->string('agent_name')->nullable();
            $table->string('agent_email')->nullable();
            $table->string('agent_phone')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_offers');
    }
};
