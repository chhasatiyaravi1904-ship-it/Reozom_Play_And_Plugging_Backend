<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\Request;

class PublicServicePackageController extends Controller
{
    public function search(Request $request, \App\Services\PackageAvailabilityService $packageService)
    {
        $request->validate([
            'zipcode' => 'required|string',
        ]);

        $zipcode = $request->zipcode;

        $packages = $packageService->getAvailablePackages($zipcode);
        $workflow = app(\App\Services\ListingWorkflowService::class);

        // Public endpoint: only public plan fields, the agent's name, and the
        // listing process the seller would go through (never email/Stripe data).
        return response()->json($packages->map(fn (ServicePackage $pkg) => [
            'id' => $pkg->id,
            'agent_id' => $pkg->agent_id,
            'name' => $pkg->name,
            'description' => $pkg->description,
            'price' => $pkg->price,
            'agent' => [
                'id' => $pkg->agent->id,
                'first_name' => $pkg->agent->first_name,
                'last_name' => $pkg->agent->last_name,
            ],
            'listing_process' => $this->processSummary($workflow, $pkg),
        ])->values());
    }

    /** The process a listing on this plan would use, or null if none resolves. */
    private function processSummary(\App\Services\ListingWorkflowService $workflow, ServicePackage $pkg): ?array
    {
        try {
            $process = $workflow->resolveListingProcess($pkg);
        } catch (\Exception) {
            return null;
        }

        $steps = collect($process->config ?? [])
            ->filter(fn ($step) => is_array($step) && ! empty($step['name']))
            ->pluck('name')
            ->values();

        return [
            'name' => $process->name,
            'steps' => $steps,
        ];
    }

    /**
     * Public ZIP matching engine: paginated packages, optionally filtered by
     * a ZIP prefix so results can update on every keystroke.
     */
    public function index(Request $request, \App\Services\PackageAvailabilityService $packageService)
    {
        $validated = $request->validate([
            'zip' => ['nullable', 'string', 'max:10', 'regex:/^[0-9]*$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $packageService->paginateByZipPrefix(
            $validated['zip'] ?? null,
            (int) ($validated['per_page'] ?? 6)
        );

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ServicePackage $pkg) => [
                'id' => $pkg->id,
                'name' => $pkg->name,
                'description' => $pkg->description,
                'price' => $pkg->price,
                'agent' => [
                    'id' => $pkg->agent->id,
                    'first_name' => $pkg->agent->first_name,
                    'last_name' => $pkg->agent->last_name,
                ],
                'zip_codes' => $pkg->zipCodes->pluck('code')->take(5)->values(),
                'zip_codes_count' => $pkg->zipCodes->count(),
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
