<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;

class ListingDocumentController extends Controller
{
    public function index(Listing $listing): JsonResponse
    {
        // Dummy response to unblock the frontend
        return response()->json([
            [
                'id' => 'deed',
                'title' => 'Property Deed',
                'description' => 'A copy of the property deed showing ownership.',
                'status' => 'pending',
                'required' => true,
            ],
            [
                'id' => 'id_proof',
                'title' => 'Photo ID',
                'description' => 'A valid government-issued photo ID.',
                'status' => 'pending',
                'required' => true,
            ]
        ]);
    }
}
