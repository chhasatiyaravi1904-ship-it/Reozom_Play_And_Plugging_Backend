<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\Request;

class ServicePackageController extends Controller
{
    public function index(Request $request)
    {
        $query = ServicePackage::with(['zipCodes', 'listingProcess']);
        
        if ($request->user()->isAgent()) {
            $query->where('agent_id', $request->user()->id);
        } elseif (! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized');
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
            'listing_process_id' => 'nullable|exists:listing_processes,id',
        ]);

        if (! $request->user()->isAgent() && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        $validated['agent_id'] = $request->user()->id;
        $processId = $validated['listing_process_id'] ?? null;
        unset($validated['listing_process_id']);

        if ($processId) {
            $processExists = \App\Models\ListingProcess::where('id', $processId)
                ->where('agent_id', $request->user()->id)
                ->exists();
            if (!$processExists && $request->user()->isAgent()) {
                return response()->json(['errors' => ['listing_process_id' => ['Unauthorized listing process.']]], 422);
            }
        }

        $package = ServicePackage::create(\Illuminate\Support\Arr::except($validated, ['assigned_zips']));

        if ($processId) {
            \App\Models\ListingProcess::where('id', $processId)->update(['service_package_id' => $package->id]);
        }

        if (!empty($validated['assigned_zips'])) {
            $zipIds = \App\Models\ZipCode::whereIn('code', $validated['assigned_zips'])->pluck('id');
            $package->zipCodes()->sync($zipIds);
        }

        return response()->json($package->load('zipCodes'), 201);
    }

    public function show(Request $request, ServicePackage $servicePackage)
    {
        if ($request->user()->isAgent()) {
            if ($servicePackage->agent_id !== $request->user()->id) {
                abort(403, 'Unauthorized');
            }
        } elseif (! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        return response()->json($servicePackage->load(['zipCodes', 'listingProcess']));
    }

    public function update(Request $request, ServicePackage $servicePackage)
    {
        if ($request->user()->isAgent()) {
            if ($servicePackage->agent_id !== $request->user()->id) {
                abort(403, 'Unauthorized');
            }
        } elseif (! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'assigned_zips' => 'nullable|array',
            'is_active' => 'boolean',
            'listing_process_id' => 'nullable|exists:listing_processes,id',
        ]);

        $processId = $validated['listing_process_id'] ?? null;
        $hasProcessKey = array_key_exists('listing_process_id', $validated);
        unset($validated['listing_process_id']);

        if ($hasProcessKey && $processId) {
            $processExists = \App\Models\ListingProcess::where('id', $processId)
                ->where('agent_id', $request->user()->id)
                ->exists();
            if (!$processExists && $request->user()->isAgent()) {
                return response()->json(['errors' => ['listing_process_id' => ['Unauthorized listing process.']]], 422);
            }
        }

        $servicePackage->update(\Illuminate\Support\Arr::except($validated, ['assigned_zips']));

        if ($hasProcessKey) {
            \App\Models\ListingProcess::where('service_package_id', $servicePackage->id)->update(['service_package_id' => null]);
            if ($processId) {
                \App\Models\ListingProcess::where('id', $processId)->update(['service_package_id' => $servicePackage->id]);
            }
        }

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
        if ($request->user()->isAgent()) {
            if ($servicePackage->agent_id !== $request->user()->id) {
                abort(403, 'Unauthorized');
            }
        } elseif (! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized');
        }

        $servicePackage->delete();
        return response()->json(null, 204);
    }
}
