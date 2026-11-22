<?php

use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Original Reozom creates only the disclosure documents until a listing is paid ("free listing"
 * set) and the full set at payment. Also re-applies the template set so existing databases get
 * the Reozom rules (GRAR off, Lead regardless of year built, MI Vacant Land trust rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->boolean('available_before_payment')->default(false)->after('is_active');
        });

        (new DocumentTemplateSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('available_before_payment');
        });
    }
};
