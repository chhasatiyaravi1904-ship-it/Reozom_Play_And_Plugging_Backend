<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Services\ListingWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ListingWorkflowController extends Controller
{
    public function show(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        if (!$listing->listing_process_id) {
            return api_error('This listing does not have an associated workflow.', 404);
        }

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

    public function submitStep(Request $request, Listing $listing, string $stepId, ListingWorkflowService $workflowService): JsonResponse
    {
        Gate::authorize('update', $listing);

        $values = $request->input('values', []);

        ListingAnswer::updateOrCreate(
            ['listing_id' => $listing->id, 'step_id' => $stepId],
            ['values' => $values]
        );

        $workflowService->calculateCompletedSteps($listing);

        // Approved Rule: Editing a published listing unpublishes it until revalidated and resubmitted
        if ($listing->status === 'published') {
            $listing->update([
                'status' => 'in_progress',
                'moderation_status' => 'edited_pending_revalidation',
            ]);
        }

        return api_success(['step' => $stepId, 'saved' => true], 'Step saved successfully.');
    }

    /**
     * Retrieve statutory disclosure schema and existing persisted answers for a listing.
     */
    public function getDisclosures(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $snapshot = $listing->workflow_snapshot ?? [];
        $disclosureSteps = [];

        // Check if snapshot contains disclosure steps
        foreach ($snapshot as $step) {
            $stepId = $step['id'] ?? '';
            $stepName = strtolower($step['name'] ?? '');
            if (str_contains($stepId, 'disclosure') || str_contains($stepName, 'disclosure')) {
                $disclosureSteps[] = $step;
            }
        }

        // If no disclosure step was defined in the custom snapshot, supply standard statutory disclosures
        if (empty($disclosureSteps)) {
            $disclosureSteps = [
                [
                    'id' => 'step_disclosures_statutory',
                    'name' => 'Statutory Property Disclosures',
                    'order' => 1,
                    'sections' => [
                        [
                            'id' => 'sec_appliances_systems',
                            'name' => 'Appliances & Working Systems',
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'hvac_condition',
                                    'name' => 'hvac_condition',
                                    'label' => 'Heating / Central Air Condition',
                                    'type' => 'radio',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Working', 'value' => 'working'],
                                        ['label' => 'Not Working', 'value' => 'not_working'],
                                        ['label' => 'Not Included', 'value' => 'not_included'],
                                    ],
                                ],
                                [
                                    'id' => 'plumbing_condition',
                                    'name' => 'plumbing_condition',
                                    'label' => 'Plumbing System Condition',
                                    'type' => 'radio',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Working', 'value' => 'working'],
                                        ['label' => 'Needs Repair', 'value' => 'needs_repair'],
                                    ],
                                ],
                                [
                                    'id' => 'electrical_condition',
                                    'name' => 'electrical_condition',
                                    'label' => 'Electrical System Condition',
                                    'type' => 'radio',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Working', 'value' => 'working'],
                                        ['label' => 'Needs Repair', 'value' => 'needs_repair'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 'sec_structural_environmental',
                            'name' => 'Structural & Environmental Disclosures',
                            'order' => 2,
                            'fields' => [
                                [
                                    'id' => 'roof_leaks',
                                    'name' => 'roof_leaks',
                                    'label' => 'Are you aware of any roof leaks or past repairs?',
                                    'type' => 'radio',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'No', 'value' => 'no'],
                                    ],
                                ],
                                [
                                    'id' => 'roof_details',
                                    'name' => 'roof_details',
                                    'label' => 'If yes, please explain roof condition details',
                                    'type' => 'textarea',
                                    'required' => false,
                                ],
                                [
                                    'id' => 'basement_water',
                                    'name' => 'basement_water',
                                    'label' => 'Has there ever been water accumulation or dampness in the basement/crawl space?',
                                    'type' => 'radio',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'No', 'value' => 'no'],
                                    ],
                                ],
                                [
                                    'id' => 'lead_paint',
                                    'name' => 'lead_paint',
                                    'label' => 'Are you aware of any lead-based paint hazard on the property?',
                                    'type' => 'radio',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        $stepIds = array_column($disclosureSteps, 'id');
        $answers = $listing->answers()->whereIn('step_id', $stepIds)->pluck('values', 'step_id');

        return api_success([
            'listingId' => $listing->id,
            'steps' => $disclosureSteps,
            'answers' => $answers,
        ]);
    }

    /**
     * Persist statutory disclosure answers and validate completeness.
     */
    public function saveDisclosures(Request $request, Listing $listing, ListingWorkflowService $workflowService): JsonResponse
    {
        Gate::authorize('update', $listing);

        $answers = $request->input('answers', []);
        if (!is_array($answers)) {
            return api_error('Answers payload must be an object of stepId => values.', 422);
        }

        foreach ($answers as $stepId => $values) {
            ListingAnswer::updateOrCreate(
                ['listing_id' => $listing->id, 'step_id' => $stepId],
                ['values' => is_array($values) ? $values : []]
            );
        }

        $workflowService->calculateCompletedSteps($listing);

        if ($listing->status === 'published') {
            $listing->update([
                'status' => 'in_progress',
                'moderation_status' => 'edited_pending_revalidation',
            ]);
        }

        return api_success([
            'saved' => true,
            'steps' => array_keys($answers),
        ], 'Disclosures saved successfully.');
    }
}
