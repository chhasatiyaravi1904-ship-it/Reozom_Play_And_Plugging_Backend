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
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference_code')->unique();
            $table->string('status', 20)->default('in_progress');
            $table->string('address');
            $table->string('city');
            $table->string('state');
            $table->string('zip', 10);
            $table->unsignedSmallInteger('steps_completed')->default(0);
            // No listing-process workflow engine exists yet — this is the
            // fixed step count sellers currently see (Disclosures,
            // Documents, Review, Submit). Will become dynamic once the
            // Listing Process Builder is wired up.
            $table->unsignedSmallInteger('steps_total')->default(4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
