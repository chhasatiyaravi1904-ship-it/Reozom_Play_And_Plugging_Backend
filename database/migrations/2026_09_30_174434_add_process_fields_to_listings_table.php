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
        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('service_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('listing_process_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropForeign(['service_package_id']);
            $table->dropForeign(['listing_process_id']);
            $table->dropColumn(['service_package_id', 'listing_process_id']);
        });
    }
};
