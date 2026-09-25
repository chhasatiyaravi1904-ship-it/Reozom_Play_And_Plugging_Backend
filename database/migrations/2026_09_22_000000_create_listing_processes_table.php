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
        Schema::create('listing_processes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('custom'); // 'default' or 'custom'
            $table->string('status')->default('draft'); // 'active' or 'draft'
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('assigned_zips')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_processes');
    }
};
