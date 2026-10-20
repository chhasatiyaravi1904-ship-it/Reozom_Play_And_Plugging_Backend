<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ListingDocumentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listings\StoreListingRequest;
use App\Http\Requests\Listings\UpdateListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ListingController extends Controller
{
    /**
     * A seller only ever sees their own listings — there is no
     * cross-account browsing here, unlike the admin listings view.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $listings = Listing::latest()->get();
        } elseif ($user->isAgent()) {
            $listings = Listing::where('user_id', $user->id)
                ->orWhereHas('servicePackage', function ($q) use ($user) {
                    $q->where('agent_id', $user->id);
                })
                ->latest()
                ->get();
        } else {
            $listings = $user->listings()->latest()->get();
        }

        return api_success(ListingResource::collection($listings));
    }

    public function store(
        StoreListingRequest $request, 
        \App\Services\PackageAvailabilityService $packageService, 
        \App\Services\ListingWorkflowService $workflowService
    ): JsonResponse {
        Gate::authorize('create', Listing::class);

        $packageId = $request->validated('packageId');
        $zip = $request->validated('zip');
        
        if (!$packageId || !$zip) {
            return api_error('Service package and ZIP code are required.', 422);
        }

        try {
            $servicePackage = $packageService->validatePackageForZip($packageId, $zip);
            $process = $workflowService->resolveListingProcess($servicePackage);
        } catch (\Exception $e) {
            return api_error($e->getMessage(), 422);
        }

        $snapshot = $workflowService->createWorkflowSnapshot($process);
        $stepsTotal = count($snapshot);

        $listing = null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $zip, $packageId, $process, $snapshot, $stepsTotal, &$listing) {
            $listing = $request->user()->listings()->create([
                'address' => $request->validated('address') ?? '',
                'city'    => $request->validated('city') ?? '',
                'state'   => $request->validated('state') ?? '',
                'zip'     => $zip,
                'service_package_id' => $packageId,
                'listing_process_id' => $process->id,
                'workflow_snapshot'  => $snapshot,
                'status' => 'in_progress',
                'moderation_status' => 'none',
                'steps_completed' => 0,
                'steps_total' => $stepsTotal,
            ]);

            if ($request->has('answers') && is_array($request->input('answers'))) {
                foreach ($request->input('answers') as $stepId => $values) {
                    $listing->answers()->create([
                        'step_id' => $stepId,
                        'values' => $values,
                    ]);
                }
            }
        });

        return api_success(new ListingResource($listing), 'Listing created.', 201);
    }

    public function show(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        return api_success(new ListingResource($listing));
    }

    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validatedData = $request->validated();

        // Approved Rule: Editing a published listing removes it from public visibility
        // until it is revalidated and republished.
        if ($listing->status === 'published') {
            $validatedData['status'] = 'in_progress';
            $validatedData['moderation_status'] = 'edited_pending_revalidation';
        }

        $listing->update($validatedData);

        return api_success(new ListingResource($listing), 'Listing updated.');
    }

    public function submit(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('submit', $listing);

        $snapshot = $listing->workflow_snapshot ?? [];
        $snapshotStepIds = array_column($snapshot, 'id');
        $answeredStepIds = $listing->answers()->pluck('step_id')->toArray();

        $stepsTotal = count($snapshot) > 0 ? count($snapshot) : $listing->steps_total;
        $validCompletedSteps = array_intersect($snapshotStepIds, $answeredStepIds);
        $completedCount = count(array_unique($validCompletedSteps));

        // Check required documents configured for this listing
        $requiredDocTypes = ListingDocumentController::getRequiredDocumentTypes($listing);
        $uploadedDocTypes = $listing->documents()->pluck('type')->toArray();
        $missingDocTypes = array_diff($requiredDocTypes, $uploadedDocTypes);
        $hasRequiredDocs = empty($missingDocTypes);

        // Approved Rule: Automatic publication after required listing completion and validation steps.
        $isComplete = ($completedCount >= $stepsTotal) && ($stepsTotal > 0) && $hasRequiredDocs;

        if ($isComplete) {
            $listing->update([
                'status' => 'published',
                'moderation_status' => 'auto_approved',
                'steps_completed' => $stepsTotal,
                'moderated_at' => now(),
            ]);

            return api_success(new ListingResource($listing), 'Listing completed, validated, and published.');
        }

        // If submitted but requirements are pending, store submitted state for review/resubmission
        $listing->update([
            'status' => 'submitted',
            'moderation_status' => 'pending_review',
            'steps_completed' => $completedCount,
        ]);

        return api_success(new ListingResource($listing), 'Listing submitted for review.');
    }
}

