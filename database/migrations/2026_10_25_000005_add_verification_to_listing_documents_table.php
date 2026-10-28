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
            $table->string('status', 32)->default('pending_review')->after('size'); // pending_review, approved, rejected, signed, unsigned
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('admin_notes')->nullable()->after('reviewed_at');
            $table->dateTime('signed_at')->nullable()->after('admin_notes');
            $table->string('signed_by')->nullable()->after('signed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_documents', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['status', 'reviewed_by', 'reviewed_at', 'admin_notes', 'signed_at', 'signed_by']);
        });
    }
};
