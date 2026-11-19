<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin plans (packages) and agent plans (service_packages) are mirrored in Stripe as a
 * Product + Price. Stripe Prices can't change, so a new price creates a new Stripe Price and
 * stripe_price_amount records which amount the current Stripe Price charges.
 */
return new class extends Migration
{
    private array $tables = ['packages', 'service_packages'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('stripe_product_id')->nullable()->index();
                $t->string('stripe_price_id')->nullable();
                $t->unsignedBigInteger('stripe_price_amount')->nullable(); // cents
                $t->timestamp('stripe_synced_at')->nullable();
                $t->string('stripe_sync_error', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(["stripe_product_id"]);
                $t->dropColumn(['stripe_product_id', 'stripe_price_id', 'stripe_price_amount', 'stripe_synced_at', 'stripe_sync_error']);
            });
        }
    }
};
