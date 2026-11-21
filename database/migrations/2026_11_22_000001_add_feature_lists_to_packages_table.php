<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Text shown on the website's "Listing Agent Packages" cards: what a plan includes (✓)
     * and what it doesn't (✕). Separate from the plan's permissions.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->json('included_features')->nullable()->after('description');
            $table->json('excluded_features')->nullable()->after('included_features');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['included_features', 'excluded_features']);
        });
    }
};
