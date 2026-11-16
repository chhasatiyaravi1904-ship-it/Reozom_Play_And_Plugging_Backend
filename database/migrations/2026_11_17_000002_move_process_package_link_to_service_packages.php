<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One listing process can serve several service packages; each package uses one process.
 * The link moves from listing_processes.service_package_id (one package per process)
 * to service_packages.listing_process_id (many packages per process).
 *
 * Links are read first and written last: on SQLite every schema change rebuilds the table,
 * which fires the ON DELETE SET NULL foreign keys and would clear links written in between.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Duplicates were removed by the previous migration; if any remain, the most recently
        // updated process wins (applied oldest first).
        $links = DB::table('listing_processes')
            ->whereNotNull('service_package_id')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get(['id', 'service_package_id']);

        Schema::table('service_packages', function (Blueprint $table) {
            $table->foreignId('listing_process_id')->nullable()->after('agent_id')
                ->constrained('listing_processes')->nullOnDelete();
        });

        Schema::table('listing_processes', function (Blueprint $table) {
            $table->dropForeign(['service_package_id']);
            $table->dropColumn('service_package_id');
        });

        foreach ($links as $process) {
            DB::table('service_packages')
                ->where('id', $process->service_package_id)
                ->update(['listing_process_id' => $process->id]);
        }
    }

    public function down(): void
    {
        // The old column holds one package per process: keep the first package of each process.
        $links = DB::table('service_packages')
            ->whereNotNull('listing_process_id')
            ->orderBy('id')
            ->get(['id', 'listing_process_id'])
            ->unique('listing_process_id');

        Schema::table('listing_processes', function (Blueprint $table) {
            $table->foreignId('service_package_id')->nullable()->after('agent_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropForeign(['listing_process_id']);
            $table->dropColumn('listing_process_id');
        });

        foreach ($links as $package) {
            DB::table('listing_processes')
                ->where('id', $package->listing_process_id)
                ->update(['service_package_id' => $package->id]);
        }
    }
};
