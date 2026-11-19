<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe Checkout payments: agents paying for an agent package, and sellers paying for the
 * agent's service package on a listing. All money goes to the platform's Stripe account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30)->index(); // agent_package | listing

            // Who paid
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Agent package payment
            $table->foreignUuid('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('agent_package_id')->nullable()->constrained('agent_packages')->nullOnDelete();

            // Listing payment: the listing, the agent's service package, and the agent it was for
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            // Amount in the smallest currency unit (cents), copied from the database price at checkout
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('description')->nullable();

            $table->string('status', 20)->default('pending')->index(); // pending | paid | failed | expired | refunded

            $table->string('stripe_session_id')->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['agent_id', 'status']);
            $table->index(['listing_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
