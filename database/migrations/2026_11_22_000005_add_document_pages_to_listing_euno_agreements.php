<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page count of every document in a signing packet, in packet order ({listing_document_id: pages}),
 * so the signed packet can be split into one signed PDF per document (original Reozom documentSplit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_euno_agreements', function (Blueprint $table) {
            $table->json('document_pages')->nullable()->after('source_file_paths');
        });
    }

    public function down(): void
    {
        Schema::table('listing_euno_agreements', function (Blueprint $table) {
            $table->dropColumn('document_pages');
        });
    }
};
