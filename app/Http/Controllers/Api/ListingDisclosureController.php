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

        $steps = $disclosureService->getDisclosureSchema($listing);
        $answers = $disclosureService->getAnswers($listing);

        return api_success([
            'listingId' => $listing->id,
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
        ]);

        $disclosureService->saveDisclosures($listing, $validated['answers']);

        return api_success([
            'listingId' => $listing->id,
            'answers' => $disclosureService->getAnswers($listing),
            'documents' => $listing->documents()->get(),
        ], 'Disclosures saved and statutory documents generated successfully.');
    }
}
