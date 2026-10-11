<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListingDocumentController extends Controller
{
    public function index(Request $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        // Mocked requirements mapping to real DB uploaded files
        $requirements = [
            [
                'id' => 'deed',
                'label' => 'Property Deed',
                'description' => 'A copy of the property deed showing ownership.',
                'required' => true,
            ],
            [
                'id' => 'id_proof',
                'label' => 'Photo ID',
                'description' => 'A valid government-issued photo ID.',
                'required' => true,
            ]
        ];

        $documents = $listing->documents()->get()->keyBy('type');

        $response = array_map(function ($req) use ($documents) {
            $uploaded = $documents->get($req['id']);
            return [
                'id' => $req['id'],
                'label' => $req['label'],
                'required' => $req['required'],
                'status' => $uploaded ? 'uploaded' : 'pending',
                'fileName' => $uploaded ? $uploaded->original_name : null,
                'uploadedAt' => $uploaded ? $uploaded->created_at->toIso8601String() : null,
            ];
        }, $requirements);

        return api_success($response);
    }

    public function store(Request $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        $request->validate([
            'document_id' => ['required', 'string', 'in:deed,id_proof'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'], // 10MB max
        ]);

        $file = $request->file('file');
        $type = $request->input('document_id');

        $path = $file->storeAs(
            "listings/{$listing->id}/documents",
            \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension(),
            'local' // Private disk
        );

        if (!$path) {
            return api_error('Failed to store document.', 500);
        }

        // Delete existing document of this type if any
        if ($existing = $listing->documents()->where('type', $type)->first()) {
            Storage::disk('local')->delete($existing->path);
            $existing->delete();
        }

        $document = $listing->documents()->create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return api_success([
            'id' => $document->type,
            'fileName' => $document->original_name,
            'status' => 'uploaded'
        ], 'Document uploaded.', 201);
    }

    public function download(Request $request, Listing $listing, $documentId)
    {
        $this->authorizeOwner($request, $listing);

        $document = $listing->documents()->where('type', $documentId)->firstOrFail();

        if (!Storage::disk('local')->exists($document->path)) {
            abort(404, 'File not found on server.');
        }

        return Storage::disk('local')->download(
            $document->path,
            $document->original_name,
            ['Content-Type' => $document->mime_type]
        );
    }

    public function destroy(Request $request, Listing $listing, $documentId): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        $document = $listing->documents()->where('type', $documentId)->firstOrFail();

        if (Storage::disk('local')->exists($document->path)) {
            Storage::disk('local')->delete($document->path);
        }

        $document->delete();

        return api_success(null, 'Document deleted.');
    }

    private function authorizeOwner(Request $request, Listing $listing): void
    {
        Gate::allowIf(fn () => $listing->user_id === $request->user()->id);
    }
}
