<?php

namespace App\Http\Controllers;

use App\Models\ListingProcess;
use Illuminate\Http\Request;

class ListingProcessController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $processes = ListingProcess::with('agent:id,first_name,last_name')->get();
        return response()->json($processes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|in:default,custom',
            'status' => 'nullable|string|in:active,draft',
            'agent_id' => 'nullable|exists:users,id',
            'assigned_zips' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        $process = ListingProcess::create($validated);

        return response()->json($process, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(ListingProcess $listingProcess)
    {
        return response()->json($listingProcess->load('agent:id,first_name,last_name'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ListingProcess $listingProcess)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'nullable|string|in:default,custom',
            'status' => 'nullable|string|in:active,draft',
            'agent_id' => 'nullable|exists:users,id',
            'assigned_zips' => 'nullable|array',
            'config' => 'nullable|array',
        ]);

        $listingProcess->update($validated);

        return response()->json($listingProcess);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ListingProcess $listingProcess)
    {
        $listingProcess->delete();
        return response()->json(null, 204);
    }
}
