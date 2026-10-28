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
        Schema::table('listing_documents', function (Blueprint $table) {
            $table->index('listing_id');
            $table->dropUnique(['listing_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_documents', function (Blueprint $table) {
            $table->unique(['listing_id', 'type']);
        });
    }
};
