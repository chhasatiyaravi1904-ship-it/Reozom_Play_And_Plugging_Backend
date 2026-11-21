<?php

namespace App\Services;

use App\Models\ListingProcess;
use App\Models\Listing;
use App\Models\ServicePackage;

class ListingWorkflowService
{
    /**
     * Resolves the required Listing Process for a Service Package.
     * It strictly checks that the process belongs to the correct Agent and Package,
     * and is active/published.
     * 
     * @param ServicePackage $servicePackage
     * @return ListingProcess
     * @throws \Exception
     */
    public function resolveListingProcess(ServicePackage $servicePackage): ListingProcess
    {
        // 1-2. The process linked to this Service Package (several packages may share it)
        $process = $servicePackage->listing_process_id
            ? ListingProcess::where('id', $servicePackage->listing_process_id)
                ->where('status', 'active')
                ->first()
            : null;

        // 3. Fallback to default active Residential process
        if (!$process) {
            $process = ListingProcess::whereNull('agent_id')
                ->where('type', 'default')
                ->where('status', 'active')
                ->where('name', 'like', '%Residential%')
                ->first();
        }

        // 4. Any default active process with non-empty config
        if (!$process) {
            $process = ListingProcess::whereNull('agent_id')
                ->where('type', 'default')
                ->where('status', 'active')
                ->whereNotNull('config')
                ->first();
        }

        if (!$process || empty($process->config)) {
            throw new \Exception('The selected service package does not have an active listing process.');
        }

        return $process;
    }

    /**
     * Create a workflow snapshot from the ListingProcess.
     * 
     * @param ListingProcess $process
     * @return array
     */
    public function createWorkflowSnapshot(ListingProcess $process): array
    {
        return $process->config ?? [];
    }

    /**
     * Get the steps from the workflow snapshot of a listing.
     * 
     * @param Listing $listing
     * @return array
     */
    public function getWorkflowSteps(Listing $listing): array
    {
        return $listing->workflow_snapshot ?? [];
    }

    /**
     * Recalculate unique completed steps and total steps, and update the listing.
     * 
     * @param Listing $listing
     * @return void
     */
    public function calculateCompletedSteps(Listing $listing): void
    {
        $snapshot = $this->getWorkflowSteps($listing);
        $stepsTotal = count($snapshot);
        
        if ($stepsTotal === 0) {
            // Failsafe in case workflow is malformed
            return;
        }

        // Get unique answered step IDs that exist in the snapshot
        // to ensure we only count actual current workflow steps.
        $snapshotStepIds = array_column($snapshot, 'id');
        
        $answeredStepIds = $listing->answers()
            ->pluck('step_id')
            ->toArray();

        // Steps that don't apply to this listing (e.g. the Condo disclosure for a single family
        // home, or disclosures for a trust-owned non-owner-occupied property) count as complete.
        $activeStepIds = array_column($listing->activeSteps(), 'id');
        $skippedStepIds = array_diff($snapshotStepIds, $activeStepIds);

        // "Pictures & Virtual Tours" is only done once the listing really has a photo; saving the
        // step's other fields (tour link, terms) alone doesn't complete it.
        if (! $listing->images()->exists()) {
            $answeredStepIds = array_diff($answeredStepIds, [\App\Models\ListingProcess::MEDIA_STEP_ID]);
        }

        $validCompletedSteps = array_merge(array_intersect($snapshotStepIds, $answeredStepIds), $skippedStepIds);
        $completedCount = count(array_unique($validCompletedSteps));

        $listing->update([
            'steps_completed' => $completedCount,
            'steps_total' => $stepsTotal,
        ]);
    }
}
