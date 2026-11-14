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
        if (Schema::hasTable('listing_processes')) {
            Schema::table('listing_processes', function (Blueprint $table) {
                if (!Schema::hasColumn('listing_processes', 'required_disclosures')) {
                    $table->json('required_disclosures')->nullable()->after('config');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('listing_processes')) {
            Schema::table('listing_processes', function (Blueprint $table) {
                if (Schema::hasColumn('listing_processes', 'required_disclosures')) {
                    $table->dropColumn('required_disclosures');
                }
            });
        }
    }
};
