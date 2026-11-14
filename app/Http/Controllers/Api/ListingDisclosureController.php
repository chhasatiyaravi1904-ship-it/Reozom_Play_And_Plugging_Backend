<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Services\DisclosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ListingDisclosureController extends Controller
{
    /**
     * Retrieve statutory disclosure requirements and current saved answers for a listing.
     */
    public function index(Request $request, Listing $listing, DisclosureService $disclosureService): JsonResponse
    {
        Gate::authorize('view', $listing);

        $available = $disclosureService->getAvailableDisclosuresForListing($listing);
        $selectedCodes = $disclosureService->getSelectedDisclosureCodes($listing);
        $steps = $disclosureService->getDisclosureSchema($listing, $selectedCodes);
        $answers = $disclosureService->getAnswers($listing);

        return api_success([
            'listingId' => $listing->id,
            'availableDisclosures' => $available,
            'selectedDisclosures' => $selectedCodes,
            'steps' => $steps,
            'answers' => $answers,
        ]);
    }

    /**
     * Store disclosure answers and automatically generate/update statutory documents.
     */
    public function store(Request $request, Listing $listing, DisclosureService $disclosureService): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'answers' => 'required|array',
            'selectedDisclosures' => 'nullable|array',
            'selectedDisclosures.*' => 'string',
        ]);

        $selectedCodes = $validated['selectedDisclosures'] ?? null;
        $disclosureService->saveDisclosures($listing, $validated['answers'], $selectedCodes);

        return api_success([
            'listingId' => $listing->id,
            'selectedDisclosures' => $disclosureService->getSelectedDisclosureCodes($listing),
            'answers' => $disclosureService->getAnswers($listing),
            'documents' => $listing->documents()->get(),
        ], 'Disclosures saved and statutory documents generated successfully.');
    }

    /**
     * Update only the required disclosure document selection for this listing.
     */
    public function select(Request $request, Listing $listing, DisclosureService $disclosureService): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'selectedDisclosures' => 'required|array',
            'selectedDisclosures.*' => 'string',
        ]);

        $selectedCodes = array_values(array_unique($validated['selectedDisclosures']));

        $listing->answers()->updateOrCreate(
            ['step_id' => 'required_disclosures'],
            ['values' => ['selected_codes' => $selectedCodes]]
        );

        $steps = $disclosureService->getDisclosureSchema($listing, $selectedCodes);
        $available = $disclosureService->getAvailableDisclosuresForListing($listing);

        return api_success([
            'listingId' => $listing->id,
            'availableDisclosures' => $available,
            'selectedDisclosures' => $selectedCodes,
            'steps' => $steps,
        ], 'Required disclosure selection updated.');
    }
}
