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
        if (!Schema::hasTable('property_disclosures')) {
            Schema::create('property_disclosures', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('title');
                $table->string('state_code', 10)->nullable();
                $table->string('property_type', 50)->nullable();
                $table->string('category', 50)->default('statutory');
                $table->text('description')->nullable();
                $table->integer('order')->default(1);
                $table->boolean('is_required')->default(true);
                $table->boolean('is_active')->default(true);
                $table->json('schema');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_disclosures');
    }
};

