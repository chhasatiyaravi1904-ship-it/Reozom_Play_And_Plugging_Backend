<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ListingDocumentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listings\StoreListingRequest;
use App\Http\Requests\Listings\UpdateListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\ListingAnswer;
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
            $listings = Listing::with(['images', 'offers', 'servicePackage', 'answers'])->latest()->get();
        } elseif ($user->isAgent()) {
            $listings = Listing::with(['images', 'offers', 'servicePackage', 'answers'])
                ->where('user_id', $user->id)
                ->orWhereHas('servicePackage', function ($q) use ($user) {
                    $q->where('agent_id', $user->id);
                })
                ->latest()
                ->get();
        } else {
            $listings = $user->listings()->with(['images', 'offers', 'servicePackage', 'answers'])->latest()->get();
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

            // Link Listing Process Required Disclosures to the newly created Listing
            $processDisclosures = $process->getRequiredDisclosures();
            if (!empty($processDisclosures)) {
                $listing->answers()->create([
                    'step_id' => 'required_disclosures',
                    'values' => ['selected_codes' => $processDisclosures],
                ]);
            }
        });

        return api_success(new ListingResource($listing), 'Listing created.', 201);
    }

    public function show(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $listing->loadMissing(['images', 'offers', 'servicePackage', 'answers']);

        return api_success(new ListingResource($listing));
    }

    /**
     * 1-Click Fast Edit: Update Listing Price with audit logging.
     * Replicates Reozom PropertyInformationController@showUpdatePrice.
     */
    public function updatePrice(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'price' => 'required|numeric|min:1',
            'reason' => 'nullable|string|max:500',
        ]);

        $newPrice = (float) $validated['price'];

        $answer = ListingAnswer::firstOrNew([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
        ]);

        $values = is_array($answer->values) ? $answer->values : [];
        $oldPrice = $values['listing_price'] ?? $values['list_price'] ?? null;
        $values['listing_price'] = $newPrice;
        $values['list_price'] = $newPrice;
        $answer->values = $values;
        $answer->save();

        $infoAnswer = ListingAnswer::where('listing_id', $listing->id)
            ->where('step_id', 'like', '%property_info%')
            ->first();
        if ($infoAnswer && is_array($infoAnswer->values)) {
            $iv = $infoAnswer->values;
            $iv['list_price'] = $newPrice;
            $infoAnswer->values = $iv;
            $infoAnswer->save();
        }

        if ($oldPrice && (float)$oldPrice !== $newPrice) {
            $listing->statusHistories()->create([
                'status' => $listing->status,
                'comment' => sprintf('Price updated from $%s to $%s%s', 
                    number_format((float)$oldPrice, 2), 
                    number_format($newPrice, 2), 
                    !empty($validated['reason']) ? " ({$validated['reason']})" : ''
                ),
                'changed_by' => $request->user()->id,
            ]);
        }

        $listing->touch();
        $listing->loadMissing(['images', 'offers', 'servicePackage', 'answers']);

        return api_success(new ListingResource($listing), 'Listing price updated successfully.');
    }

    /**
     * 1-Click Fast Edit: Update Public Remarks and Title.
     * Replicates Reozom ListingsController@editRemarks.
     */
    public function updateRemarks(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'listing_title' => 'nullable|string|max:250',
            'remarks' => 'nullable|string|max:4000',
            'directions' => 'nullable|string|max:1000',
        ]);

        $answer = ListingAnswer::firstOrNew([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
        ]);

        $values = is_array($answer->values) ? $answer->values : [];
        if (array_key_exists('listing_title', $validated)) {
            $values['listing_title'] = $validated['listing_title'];
        }
        if (array_key_exists('remarks', $validated)) {
            $values['remarks'] = $validated['remarks'];
        }
        if (array_key_exists('directions', $validated)) {
            $values['directions'] = $validated['directions'];
        }
        $answer->values = $values;
        $answer->save();

        $listing->touch();
        $listing->loadMissing(['images', 'offers', 'servicePackage', 'answers']);

        return api_success(new ListingResource($listing), 'Listing remarks updated successfully.');
    }

    /**
     * 1-Click Fast Edit: Update Buyer Agent Compensation & Seller Concessions.
     * Replicates Reozom PropertyInformationController@showUpdateCompensation.
     */
    public function updateCompensation(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'compensation' => 'nullable|numeric|min:0',
            'compensation_type' => 'nullable|integer|in:1,2',
            'direct_buyer_rebate' => 'nullable|numeric|min:0',
            'seller_concessions' => 'nullable|numeric|min:0',
        ]);

        $answer = ListingAnswer::firstOrNew([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
        ]);

        $values = is_array($answer->values) ? $answer->values : [];
        foreach ($validated as $k => $v) {
            $values[$k] = $v;
        }
        $answer->values = $values;
        $answer->save();

        $listing->touch();
        $listing->loadMissing(['images', 'offers', 'servicePackage', 'answers']);

        return api_success(new ListingResource($listing), 'Compensation terms updated successfully.');
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
        $stepsTotal = count($snapshot) > 0 ? count($snapshot) : $listing->steps_total;

        // Skip review status and directly publish
        $listing->update([
            'status' => 'published',
            'moderation_status' => 'auto_approved',
            'steps_completed' => $stepsTotal,
            'moderated_at' => now(),
            'status_changed_date' => now(),
        ]);

        return api_success(new ListingResource($listing), 'Listing published successfully.');
    }

    public function publish(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $snapshot = $listing->workflow_snapshot ?? [];
        $stepsTotal = count($snapshot) > 0 ? count($snapshot) : $listing->steps_total;

        $listing->update([
            'status' => 'published',
            'moderation_status' => 'auto_approved',
            'steps_completed' => $stepsTotal,
            'moderated_at' => now(),
            'status_changed_date' => now(),
        ]);

        return api_success(new ListingResource($listing), 'Listing published successfully.');
    }
}

