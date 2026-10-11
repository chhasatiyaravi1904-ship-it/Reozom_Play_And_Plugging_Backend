<?php

namespace App\Http\Controllers;

use App\Models\ListingProcess;
use App\Http\Requests\SaveListingProcessRequest;
use Illuminate\Http\Request;

class ListingProcessController extends Controller
{
    private function enforceSystemStep(array $config): array
    {
        $systemStepId = 'system_public_info';
        $systemStepIndex = -1;
        
        foreach ($config as $index => $step) {
            if (($step['id'] ?? '') === $systemStepId) {
                $systemStepIndex = $index;
                break;
            }
        }
        
        $systemStep = [
            'id' => $systemStepId,
            'name' => 'Public Listing Information',
            'type' => 'public_listing',
            'isSystem' => true,
            'order' => 1,
            'visible' => true,
            'sections' => [
                [
                    'id' => 'public_basic_information',
                    'order' => 1,
                    'name' => 'Basic Information',
                    'fields' => [
                        [
                            'id' => 'listing_title',
                            'name' => 'listing_title',
                            'label' => 'Listing Title',
                            'type' => 'text',
                            'required' => true,
                            'active' => true,
                        ],
                        [
                            'id' => 'listing_price',
                            'name' => 'listing_price',
                            'label' => 'Price',
                            'type' => 'number',
                            'required' => true,
                            'active' => true,
                        ],
                        [
                            'id' => 'property_type',
                            'name' => 'property_type',
                            'label' => 'Property Type',
                            'type' => 'select',
                            'required' => true,
                            'active' => true,
                            'options' => [
                                ['label' => 'Single Family', 'value' => 'single_family'],
                                ['label' => 'Condo', 'value' => 'condo'],
                                ['label' => 'Townhouse', 'value' => 'townhouse']
                            ]
                        ],
                        [
                            'id' => 'bedrooms',
                            'name' => 'bedrooms',
                            'label' => 'Bedrooms',
                            'type' => 'number',
                            'required' => true,
                            'active' => true,
                        ],
                        [
                            'id' => 'bathrooms',
                            'name' => 'bathrooms',
                            'label' => 'Bathrooms',
                            'type' => 'number',
                            'required' => true,
                            'active' => true,
                        ],
                        [
                            'id' => 'square_feet',
                            'name' => 'square_feet',
                            'label' => 'Square Feet',
                            'type' => 'number',
                            'required' => true,
                            'active' => true,
                        ],
                    ]
                ]
            ]
        ];

        if ($systemStepIndex === -1) {
            array_unshift($config, $systemStep);
            $systemStepIndex = 0;
        } else {
            $config[$systemStepIndex]['id'] = $systemStepId;
            $config[$systemStepIndex]['type'] = 'public_listing';
            $config[$systemStepIndex]['isSystem'] = true;
        }

        foreach ($config as $index => &$step) {
            $step['order'] = $index + 1;
            if (($step['id'] ?? '') === $systemStepId && $index !== $systemStepIndex) {
                $step['id'] = 'step_' . uniqid();
                unset($step['type'], $step['isSystem']);
            }
        }
        
        return $config;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        if (!$user->isAdmin() && !$user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $query = ListingProcess::with('agent:id,first_name,last_name');

        if ($user->isAgent()) {
            $query->where('agent_id', $user->id)
                  ->orWhere(function($q) {
                      $q->whereNull('agent_id')->where('type', 'default');
                  });
        } else {
            $query->whereNull('agent_id');
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SaveListingProcessRequest $request)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAgent()) {
            if (!$user->hasActivePackage()) {
                return response()->json(['message' => 'Active package required to create listing processes.'], 403);
            }

            $activePackage = $user->currentAgentPackage?->package;
            if ($activePackage && $activePackage->max_listing_processes !== null) {
                $count = $user->listingProcesses()->count();
                if ($count >= $activePackage->max_listing_processes) {
                    return response()->json(['message' => 'Package limit reached.'], 422);
                }
            }
        }

        $validated = $request->validated();

        if ($user->isAgent()) {
            $validated['agent_id'] = $user->id;

            if (empty($validated['config'])) {
                $validated['config'] = [];
            }
        } else {
            $validated['agent_id'] = null;
        }

        $validated['config'] = $this->enforceSystemStep($validated['config'] ?? []);

        if (($validated['type'] ?? null) === 'default') {
            $query = ListingProcess::where('type', 'default');
            if ($user->isAgent()) {
                $query->where('agent_id', $user->id);
            } else {
                $query->whereNull('agent_id');
            }
            $query->update(['type' => 'custom']);
        }

        $process = ListingProcess::create($validated);

        return response()->json($process, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, ListingProcess $listingProcess)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAgent() && $listingProcess->agent_id !== $user->id) {
            if ($listingProcess->agent_id !== null || $listingProcess->type !== 'default') {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        if ($user->isAdmin() && $listingProcess->agent_id !== null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($listingProcess->load('agent:id,first_name,last_name'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SaveListingProcessRequest $request, ListingProcess $listingProcess)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAgent() && $listingProcess->agent_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAdmin() && $listingProcess->agent_id !== null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();

        if ($request->user()->isAgent()) {
            unset($validated['agent_id']);
        }

        $validated['config'] = $this->enforceSystemStep($validated['config'] ?? []);

        if (($validated['type'] ?? null) === 'default' && $listingProcess->type !== 'default') {
            $query = ListingProcess::where('type', 'default');
            if ($request->user()->isAgent()) {
                $query->where('agent_id', $request->user()->id);
            } else {
                $query->whereNull('agent_id');
            }
            $query->update(['type' => 'custom']);
        }

        $listingProcess->update($validated);

        return response()->json($listingProcess);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, ListingProcess $listingProcess)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAgent() && $listingProcess->agent_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAdmin() && $listingProcess->agent_id !== null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $listingProcess->delete();
        return response()->json(null, 204);
    }
}
