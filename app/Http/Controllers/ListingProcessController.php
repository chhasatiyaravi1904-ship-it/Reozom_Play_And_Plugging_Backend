<?php

namespace App\Http\Controllers;

use App\Models\ListingProcess;
use App\Models\PropertyDisclosure;
use App\Http\Requests\SaveListingProcessRequest;
use Illuminate\Http\Request;

class ListingProcessController extends Controller
{
    /**
     * Resolve the config and disclosure selection for an update.
     *
     * - Neither sent (rename, status toggle): the stored config is kept as is.
     * - required_disclosures sent (settings modal): disclosure steps are synced to the selection.
     * - Only config sent (builder): the disclosure steps in the config are the selection, so
     *   deleting a disclosure step in the builder unselects it. Processes that have never used
     *   disclosure steps keep their required_disclosures untouched.
     */
    private function applyConfigAndDisclosures(Request $request, array &$validated, ListingProcess $process): void
    {
        $hasConfig = $request->has('config');
        $hasDisclosures = $request->has('required_disclosures');

        if (! $hasConfig && ! $hasDisclosures) {
            unset($validated['config']);

            return;
        }

        $config = $hasConfig ? ($validated['config'] ?? []) : ($process->config ?? []);

        if ($hasDisclosures) {
            $codes = $validated['required_disclosures'] ?? [];
        } elseif ($process->hasDisclosureSteps() || ListingProcess::disclosureCodesFromConfig($config) !== []) {
            $codes = ListingProcess::disclosureCodesFromConfig($config);
            $validated['required_disclosures'] = $codes;
        } else {
            $validated['config'] = ListingProcess::enforceLockedSteps($config);

            return;
        }

        $validated['config'] = ListingProcess::enforceLockedSteps(ListingProcess::syncDisclosureSteps($config, $codes));
    }

    /**
     * Same ownership rules as update/destroy: agents modify only their own processes,
     * admins modify only system (agent-less) processes.
     */
    private function denyModify($user, ListingProcess $process)
    {
        if (! $user->isAdmin() && ! $user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAgent() && $process->agent_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAdmin() && $process->agent_id !== null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return null;
    }

    private function denyAgentPackageLimit($user)
    {
        $blocker = ListingProcess::agentCreationBlocker($user);

        return $blocker ? response()->json(['message' => $blocker[1]], $blocker[0]) : null;
    }

    /**
     * Copy a process (typically a system default) into a new custom process the user can edit,
     * including its disclosure steps and questions.
     */
    public function copy(Request $request, ListingProcess $listingProcess)
    {
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $canSee = $user->isAgent()
            ? ($listingProcess->agent_id === $user->id || ($listingProcess->agent_id === null && $listingProcess->type === 'default'))
            : $listingProcess->agent_id === null;

        if (! $canSee) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isAgent() && ($error = $this->denyAgentPackageLimit($user))) {
            return $error;
        }

        $validated = $request->validate(['name' => 'nullable|string|max:255']);

        $copy = $listingProcess->copyFor(
            $user->isAgent() ? $user->id : null,
            $validated['name'] ?? $listingProcess->name.' (Copy)'
        );

        return response()->json($copy, 201);
    }

    /**
     * Replace a customized disclosure step with a fresh copy of the master disclosure questions.
     */
    public function resetDisclosureStep(Request $request, ListingProcess $listingProcess, string $code)
    {
        if ($error = $this->denyModify($request->user(), $listingProcess)) {
            return $error;
        }

        $master = PropertyDisclosure::where('code', $code)->first();
        if (! $master) {
            return response()->json(['message' => 'Disclosure not found.'], 404);
        }

        $config = $listingProcess->config ?? [];
        $found = false;
        foreach ($config as $index => $step) {
            if (ListingProcess::isDisclosureStep($step) && $step['disclosure_code'] === $code) {
                $fresh = ListingProcess::buildDisclosureStep($master);
                $fresh['order'] = $step['order'] ?? $index + 1;
                $config[$index] = $fresh;
                $found = true;
            }
        }

        if (! $found) {
            return response()->json(['message' => 'This process has no step for that disclosure.'], 404);
        }

        $listingProcess->update(['config' => $config]);

        return response()->json($listingProcess->fresh());
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
     * Document templates a process can generate (for the Documents toggles in process settings).
     */
    public function documentTemplates(Request $request)
    {
        $user = $request->user();
        if (! $user->isAdmin() && ! $user->isAgent()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(
            \App\Models\DocumentTemplate::active()
                ->orderBy('state_code')->orderBy('property_type')->orderBy('order')
                ->get(['id', 'code', 'name', 'state_code', 'property_type', 'source', 'disclosure_codes', 'requires_signature'])
        );
    }

    /**
     * Get available active disclosures for linking with listing processes.
     */
    public function availableDisclosures(Request $request)
    {
        $state = $request->query('state_code');
        $type = $request->query('property_type');

        $query = \App\Models\PropertyDisclosure::active()->orderBy('order');

        if ($state && $state !== 'ALL') {
            $query->where(function ($q) use ($state) {
                $q->whereNull('state_code')
                  ->orWhere('state_code', '')
                  ->orWhere('state_code', 'ALL')
                  ->orWhere('state_code', strtoupper($state));
            });
        }

        if ($type && $type !== 'all') {
            $query->where(function ($q) use ($type) {
                $q->whereNull('property_type')
                  ->orWhere('property_type', '')
                  ->orWhere('property_type', 'all')
                  ->orWhere('property_type', strtolower($type));
            });
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

        if ($user->isAgent() && ($error = $this->denyAgentPackageLimit($user))) {
            return $error;
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

        $validated['config'] = ListingProcess::enforceLockedSteps(ListingProcess::syncDisclosureSteps(
            $validated['config'] ?? [],
            $validated['required_disclosures'] ?? []
        ));

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

        $this->applyConfigAndDisclosures($request, $validated, $listingProcess);

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
