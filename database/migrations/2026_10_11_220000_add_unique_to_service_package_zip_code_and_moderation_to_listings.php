<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Safely remove any duplicate records from service_package_zip_code before adding unique index
        if (Schema::hasTable('service_package_zip_code')) {
            // Find duplicate IDs to keep the lowest id per pair
            $duplicates = DB::table('service_package_zip_code')
                ->select('service_package_id', 'zip_code_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
                ->groupBy('service_package_id', 'zip_code_id')
                ->having('total', '>', 1)
                ->get();

            foreach ($duplicates as $duplicate) {
                DB::table('service_package_zip_code')
                    ->where('service_package_id', $duplicate->service_package_id)
                    ->where('zip_code_id', $duplicate->zip_code_id)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->delete();
            }

            Schema::table('service_package_zip_code', function (Blueprint $table) {
                $table->unique(['service_package_id', 'zip_code_id'], 'sp_zip_unique');
            });
        }

        // 2. Add moderation fields to listings table
        Schema::table('listings', function (Blueprint $table) {
            $table->string('moderation_status', 30)->default('none')->after('status')->index();
            $table->foreignId('moderated_by')->nullable()->after('moderation_status')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
            $table->text('rejection_reason')->nullable()->after('moderated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('service_package_zip_code')) {
            Schema::table('service_package_zip_code', function (Blueprint $table) {
                $table->dropUnique('sp_zip_unique');
            });
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->dropForeign(['moderated_by']);
            $table->dropColumn(['moderation_status', 'moderated_by', 'moderated_at', 'rejection_reason']);
        });
    }
};
