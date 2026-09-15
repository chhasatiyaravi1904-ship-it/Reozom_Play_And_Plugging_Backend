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
        Schema::create('agent_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Restricted, not cascaded: deleting a package that agents have
            // already selected would silently erase their purchase history.
            $table->foreignUuid('package_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_packages');
    }
};
