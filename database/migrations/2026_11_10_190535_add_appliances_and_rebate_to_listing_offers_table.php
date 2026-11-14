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
        Schema::table('listing_offers', function (Blueprint $table) {
            if (!Schema::hasColumn('listing_offers', 'direct_buyer_rebate')) {
                $table->decimal('direct_buyer_rebate', 10, 2)->nullable()->after('offer_amount');
            }
            if (!Schema::hasColumn('listing_offers', 'appliances')) {
                $table->json('appliances')->nullable()->after('contingencies');
            }
            if (!Schema::hasColumn('listing_offers', 'other_appliances')) {
                $table->text('other_appliances')->nullable()->after('appliances');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_offers', function (Blueprint $table) {
            if (Schema::hasColumn('listing_offers', 'other_appliances')) {
                $table->dropColumn('other_appliances');
            }
            if (Schema::hasColumn('listing_offers', 'appliances')) {
                $table->dropColumn('appliances');
            }
            if (Schema::hasColumn('listing_offers', 'direct_buyer_rebate')) {
                $table->dropColumn('direct_buyer_rebate');
            }
        });
    }
};
