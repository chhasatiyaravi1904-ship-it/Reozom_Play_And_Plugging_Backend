<?php

namespace App\Http\Controllers;

use App\Models\ListingProcess;
use Illuminate\Http\Request;

class ListingProcessController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = ListingProcess::with('agent:id,first_name,last_name');

        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();

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

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|in:default,custom',
            'status' => 'nullable|string|in:active,draft',
            'agent_id' => 'nullable|exists:users,id',
            'service_package_id' => 'nullable|exists:service_packages,id',
            'assigned_zips' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        if ($user->isAgent()) {
            $validated['agent_id'] = $user->id;

            // Automatically seed with the system default config if none is provided
            if (empty($validated['config'])) {
                $systemDefault = ListingProcess::whereNull('agent_id')->where('type', 'default')->first()
                    ?? ListingProcess::whereNull('agent_id')->first();
                    
                if ($systemDefault && !empty($systemDefault->config)) {
                    $validated['config'] = $systemDefault->config;
                }
            }
        }

        $process = ListingProcess::create($validated);

        return response()->json($process, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, ListingProcess $listingProcess)
    {
        if ($request->user()->isAgent() && $listingProcess->agent_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($listingProcess->load('agent:id,first_name,last_name'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ListingProcess $listingProcess)
    {
        if ($request->user()->isAgent() && $listingProcess->agent_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'nullable|string|in:default,custom',
            'status' => 'nullable|string|in:active,draft',
            'agent_id' => 'nullable|exists:users,id',
            'service_package_id' => 'nullable|exists:service_packages,id',
            'assigned_zips' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        if ($request->user()->isAgent()) {
            unset($validated['agent_id']);
        }

        $listingProcess->update($validated);

        return response()->json($listingProcess);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, ListingProcess $listingProcess)
    {
        if ($request->user()->isAgent() && $listingProcess->agent_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $listingProcess->delete();
        return response()->json(null, 204);
    }
}
