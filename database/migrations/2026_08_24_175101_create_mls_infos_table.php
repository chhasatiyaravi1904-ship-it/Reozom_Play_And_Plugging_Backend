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
        Schema::create('mls_infos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mls_directory_id')->constrained('mls_directories')->cascadeOnDelete();
            $table->text('title')->nullable();
            $table->json('countries')->nullable();
            $table->text('public_websites_title')->nullable();
            $table->json('websites')->nullable();
            $table->text('info')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mls_infos');
    }
};
