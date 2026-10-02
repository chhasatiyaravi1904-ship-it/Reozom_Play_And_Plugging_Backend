<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ListingWorkflowController extends Controller
{
    public function show(Request $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        if (!$listing->listing_process_id) {
            return api_error('This listing does not have an associated workflow.', 404);
        }

        $process = $listing->listingProcess;
        if (!$listing->workflow_snapshot) {
            return api_error('Workflow configuration is missing.', 404);
        }

        return api_success([
            'listingId' => $listing->id,
            'processId' => $listing->listing_process_id,
            'steps'     => $listing->workflow_snapshot,
            'answers'   => $listing->answers->pluck('values', 'step_id'),
        ]);
    }

    public function submitStep(Request $request, Listing $listing, string $stepId): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        $values = $request->input('values', []);

        \App\Models\ListingAnswer::updateOrCreate(
            ['listing_id' => $listing->id, 'step_id' => $stepId],
            ['values' => $values]
        );

        return api_success(['step' => $stepId, 'saved' => true], 'Step saved successfully.');
    }

    private function authorizeOwner(Request $request, Listing $listing): void
    {
        Gate::allowIf(fn () => $listing->user_id === $request->user()->id || $request->user()->isAdmin());
    }
}
