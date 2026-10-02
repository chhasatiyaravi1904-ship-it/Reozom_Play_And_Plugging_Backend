<?php

namespace App\Http\Controllers\Api;

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
        $listings = $request->user()->listings()->latest()->get();

        return api_success(ListingResource::collection($listings));
    }

    public function store(StoreListingRequest $request): JsonResponse
    {
        $packageId = $request->validated('packageId');
        
        $process = null;

        if ($packageId) {
            $servicePackage = \App\Models\ServicePackage::find($packageId);
            
            if ($servicePackage) {
                // 1. Try process explicitly linked to package
                $process = \App\Models\ListingProcess::where('service_package_id', $packageId)->first();
                
                // 2. Try process assigned to specific zip for this agent
                $zip = $request->validated('zip');
                if (!$process && $zip) {
                    $process = \App\Models\ListingProcess::where('agent_id', $servicePackage->agent_id)
                        ->whereJsonContains('assigned_zips', $zip)
                        ->first();
                }
                
                // 3. Try agent's default process
                if (!$process) {
                    $process = \App\Models\ListingProcess::where('agent_id', $servicePackage->agent_id)
                        ->where('type', 'default')
                        ->first();
                }
            }
        }
        
        // 4. Fallback if no package or no process found via package
        if (!$process) {
            if ($request->user()->isAgent()) {
                $process = \App\Models\ListingProcess::where('agent_id', $request->user()->id)
                    ->where('type', 'default')
                    ->first();
            }
            
            // Final fallback to system default
            if (!$process) {
                $process = \App\Models\ListingProcess::whereNull('agent_id')
                    ->where('type', 'default')
                    ->first() ?? \App\Models\ListingProcess::whereNull('agent_id')->first();
            }
        }
        
        $processId = $process?->id;

        $stepsTotal = 4; // Disclosures, Documents, Review, Submit
        if (isset($process) && $process && is_array($process->config)) {
            $stepsTotal += count($process->config);
        }

        $listing = $request->user()->listings()->create([
            'address' => $request->validated('address') ?? '',
            'city'    => $request->validated('city') ?? '',
            'state'   => $request->validated('state') ?? '',
            'zip'     => $request->validated('zip'),
            'service_package_id' => $packageId,
            'listing_process_id' => $processId,
            'workflow_snapshot'  => $process ? $process->config : null,
            'status' => 'in_progress',
            'steps_completed' => 0,
            'steps_total' => $stepsTotal,
        ]);

        return api_success(new ListingResource($listing), 'Listing created.', 201);
    }

    public function show(Request $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        return api_success(new ListingResource($listing));
    }

    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        $listing->update($request->validated());

        return api_success(new ListingResource($listing), 'Listing updated.');
    }

    public function submit(Request $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        $listing->update([
            'status' => 'submitted',
            'steps_completed' => $listing->steps_total,
        ]);

        return api_success(new ListingResource($listing), 'Listing submitted.');
    }

    private function authorizeOwner(Request $request, Listing $listing): void
    {
        Gate::allowIf(fn () => $listing->user_id === $request->user()->id);
    }
}
