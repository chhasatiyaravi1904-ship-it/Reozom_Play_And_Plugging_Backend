<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $package = DB::transaction(function () use ($request, $validated, $processId) {
            $process = $processId ? $this->resolveProcessForPackage($request->user(), (int) $processId, $validated['name']) : null;

            $package = ServicePackage::create(\Illuminate\Support\Arr::except($validated, ['assigned_zips']));

            $process?->update(['service_package_id' => $package->id]);

            if (!empty($validated['assigned_zips'])) {
                $zipIds = \App\Models\ZipCode::whereIn('code', $validated['assigned_zips'])->pluck('id');
                $package->zipCodes()->sync($zipIds);
            }

            return $package;
        });

        return response()->json($package->load('zipCodes'), 201);
    }

    /**
     * Agents link their own processes. A shared system default can only belong to one package,
     * so picking one gives the agent their own copy (with all steps and disclosure questions).
     */
    private function resolveProcessForPackage(User $user, int $processId, string $packageName): ListingProcess
    {
        $process = ListingProcess::findOrFail($processId);

        if (! $user->isAgent() || $process->agent_id === $user->id) {
            return $process;
        }

        if (! $process->isSystemDefault()) {
            throw ValidationException::withMessages([
                'listing_process_id' => 'Select one of your own listing processes or a system default process.',
            ]);
        }

        if ($blocker = ListingProcess::agentCreationBlocker($user)) {
            throw ValidationException::withMessages([
                'listing_process_id' => "Can't copy the system process for this package: {$blocker[1]}",
            ]);
        }

        return $process->copyFor($user->id, "{$process->name} - {$packageName}");
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

        DB::transaction(function () use ($request, $servicePackage, $validated, $processId, $hasProcessKey) {
            // Re-selecting the process already linked to this package must not create another copy
            $alreadyLinked = $processId && ListingProcess::where('id', $processId)
                ->where('service_package_id', $servicePackage->id)->exists();

            $process = ($hasProcessKey && $processId && ! $alreadyLinked)
                ? $this->resolveProcessForPackage($request->user(), (int) $processId, $validated['name'] ?? $servicePackage->name)
                : null;

            $servicePackage->update(\Illuminate\Support\Arr::except($validated, ['assigned_zips']));

            if ($hasProcessKey && ! $alreadyLinked) {
                ListingProcess::where('service_package_id', $servicePackage->id)->update(['service_package_id' => null]);
                $process?->update(['service_package_id' => $servicePackage->id]);
            }

            if (array_key_exists('assigned_zips', $validated)) {
                if (!empty($validated['assigned_zips'])) {
                    $zipIds = \App\Models\ZipCode::whereIn('code', $validated['assigned_zips'])->pluck('id');
                    $servicePackage->zipCodes()->sync($zipIds);
                } else {
                    $servicePackage->zipCodes()->detach();
                }
            }
        });

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
