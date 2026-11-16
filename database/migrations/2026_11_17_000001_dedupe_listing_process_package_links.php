<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A service package belongs to one listing process. Seed data linked some packages to both the
 * system default process and the agent's own process; new listings then got whichever row the
 * database returned first.
 *
 * Keep one link per package: the package agent's own process (latest updated), otherwise the
 * latest updated process. Only listing_processes.service_package_id is cleared; listings keep
 * the process they were created with.
 */
return new class extends Migration
{
    public function up(): void
    {
        $packageIds = DB::table('listing_processes')
            ->whereNotNull('service_package_id')
            ->groupBy('service_package_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('service_package_id');

        foreach ($packageIds as $packageId) {
            $agentId = DB::table('service_packages')->where('id', $packageId)->value('agent_id');

            $processes = DB::table('listing_processes')
                ->where('service_package_id', $packageId)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(['id', 'agent_id']);

            $keep = ($agentId !== null ? $processes->firstWhere('agent_id', $agentId) : null)
                ?? $processes->first();

            DB::table('listing_processes')
                ->where('service_package_id', $packageId)
                ->where('id', '!=', $keep->id)
                ->update(['service_package_id' => null]);
        }
    }

    public function down(): void
    {
        // Data cleanup only; the removed duplicate links are not restored.
    }
};
