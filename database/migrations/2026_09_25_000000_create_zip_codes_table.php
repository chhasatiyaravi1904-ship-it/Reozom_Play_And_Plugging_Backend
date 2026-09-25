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
        Schema::create('zip_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 10);
            $table->foreignUuid('state_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('county_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique zip code across the platform (or maybe scoped to city, but standard US ZIPs are globally unique)
            $table->unique('code');

            // Indexes for querying efficiency
            $table->index('state_id');
            $table->index('county_id');
            $table->index('city_id');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zip_codes');
    }
};
