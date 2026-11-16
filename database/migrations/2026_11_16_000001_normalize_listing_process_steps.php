<?php

use App\Models\ListingProcess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bring existing listing processes in line with the original Reozom wizard:
 * remove the "Public Listing Information" step, lock Map and Pictures & Virtual Tours,
 * and add the Payment step at the end. All other steps (including agent customizations)
 * keep their content and order. Listing snapshots are not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listing_processes')->orderBy('id')->each(function ($process) {
            $config = json_decode($process->config ?? '[]', true);
            if (! is_array($config)) {
                $config = [];
            }

            DB::table('listing_processes')->where('id', $process->id)->update([
                'config' => json_encode(ListingProcess::enforceLockedSteps($config)),
            ]);
        });
    }

    public function down(): void
    {
        // The removed public-info step held only duplicated data; nothing to restore.
    }
};
