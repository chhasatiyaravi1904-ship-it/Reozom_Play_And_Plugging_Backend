<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller payments are split: the agent's Connect account receives the payment minus the
 * platform fee and Stripe's processing fee (both charged to the agent). Agents withdraw to
 * their bank with manual payouts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Admin-editable platform settings (e.g. platform fee)
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Fee breakdown per payment, all in cents
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_fee')->default(0)->after('currency');
            $table->unsignedBigInteger('processing_fee')->default(0)->after('platform_fee');
            $table->unsignedBigInteger('agent_amount')->default(0)->after('processing_fee');
            $table->string('destination_account_id')->nullable()->after('agent_amount');
            $table->string('stripe_refund_id')->nullable()->after('stripe_payment_intent_id');
        });

        // Agent withdrawals (Stripe payouts from the agent's Connect account to their bank)
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->string('stripe_account_id');
            $table->string('stripe_payout_id')->nullable()->unique();
            $table->unsignedBigInteger('amount'); // cents
            $table->string('currency', 3);
            $table->string('status', 20)->default('pending')->index(); // pending | in_transit | paid | failed | canceled
            $table->string('failure_message')->nullable();
            $table->date('arrival_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['platform_fee', 'processing_fee', 'agent_amount', 'destination_account_id', 'stripe_refund_id']);
        });

        Schema::dropIfExists('platform_settings');
    }
};
