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
        // Must belong to the selected Service Package and its Agent, and must be active.
        $process = ListingProcess::where('service_package_id', $servicePackage->id)
            ->where('agent_id', $servicePackage->agent_id)
            ->where('status', 'active')
            ->first();

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
            
        $validCompletedSteps = array_intersect($snapshotStepIds, $answeredStepIds);
        $completedCount = count(array_unique($validCompletedSteps));

        $listing->update([
            'steps_completed' => $completedCount,
            'steps_total' => $stepsTotal,
        ]);
    }
}
