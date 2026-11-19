<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents' Stripe Connect (Express) payout accounts. Identity and bank details live only in
 * Stripe; we keep the account ID and the status flags Stripe reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->unique();
            $table->boolean('stripe_details_submitted')->default(false);
            $table->boolean('stripe_charges_enabled')->default(false);
            $table->boolean('stripe_payouts_enabled')->default(false);
            $table->string('stripe_disabled_reason')->nullable();
            $table->unsignedInteger('stripe_requirements_due')->default(0);
            $table->timestamp('stripe_account_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn([
                'stripe_account_id', 'stripe_details_submitted', 'stripe_charges_enabled', 'stripe_payouts_enabled',
                'stripe_disabled_reason', 'stripe_requirements_due', 'stripe_account_updated_at',
            ]);
        });
    }
};
