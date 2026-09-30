<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\Request;

class ServicePackageController extends Controller
{
    public function index(Request $request)
    {
        $query = ServicePackage::with('zipCodes');
        
        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'assigned_zips' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $validated['agent_id'] = $request->user()->id;

        $package = ServicePackage::create(\Illuminate\Support\Arr::except($validated, ['assigned_zips']));

        if (!empty($validated['assigned_zips'])) {
            $zipIds = \App\Models\ZipCode::whereIn('code', $validated['assigned_zips'])->pluck('id');
            $package->zipCodes()->sync($zipIds);
        }

        return response()->json($package->load('zipCodes'), 201);
    }

    public function show(Request $request, ServicePackage $servicePackage)
    {
        if ($request->user()->isAgent() && $servicePackage->agent_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($servicePackage->load('zipCodes'));
    }

    public function update(Request $request, ServicePackage $servicePackage)
    {
        if ($request->user()->isAgent() && $servicePackage->agent_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'assigned_zips' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $servicePackage->update(\Illuminate\Support\Arr::except($validated, ['assigned_zips']));

        if (array_key_exists('assigned_zips', $validated)) {
            if (!empty($validated['assigned_zips'])) {
                $zipIds = \App\Models\ZipCode::whereIn('code', $validated['assigned_zips'])->pluck('id');
                $servicePackage->zipCodes()->sync($zipIds);
            } else {
                $servicePackage->zipCodes()->detach();
            }
        }

        return response()->json($servicePackage->load('zipCodes'));
    }

    public function destroy(Request $request, ServicePackage $servicePackage)
    {
        if ($request->user()->isAgent() && $servicePackage->agent_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $servicePackage->delete();
        return response()->json(null, 204);
    }
}
