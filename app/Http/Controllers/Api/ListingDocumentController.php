<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ListingDocumentController extends Controller
{
    /**
     * Resolve document requirements for this listing.
     * Can be configured via workflow snapshot or platform default configuration.
     */
    private function resolveRequirements(Listing $listing): array
    {
        $snapshot = $listing->workflow_snapshot ?? [];
        $configuredDocs = [];

        // Check if workflow snapshot explicitly defines required documents
        foreach ($snapshot as $step) {
            if (($step['type'] ?? '') === 'documents' || str_contains($step['id'] ?? '', 'document')) {
                foreach ($step['sections'] ?? [] as $section) {
                    foreach ($section['fields'] ?? [] as $field) {
                        if (($field['type'] ?? '') === 'file') {
                            $configuredDocs[] = [
                                'id' => $field['id'],
                                'label' => $field['label'] ?? $field['name'],
                                'description' => $field['helpText'] ?? 'Upload required documentation.',
                                'required' => (bool) ($field['required'] ?? false),
                            ];
                        }
                    }
                }
            }
        }

        if (!empty($configuredDocs)) {
            return $configuredDocs;
        }

        // Standard configurable platform requirements
        return [
            [
                'id' => 'deed',
                'label' => 'Property Deed',
                'description' => 'A recorded copy of the property deed verifying current ownership.',
                'required' => true,
            ],
            [
                'id' => 'id_proof',
                'label' => 'Government Photo ID',
                'description' => 'A valid government-issued photo ID of the property owner.',
                'required' => true,
            ],
            [
                'id' => 'tax_record',
                'label' => 'Recent Property Tax Bill',
                'description' => 'Latest municipal property tax assessment or bill.',
                'required' => false,
            ],
            [
                'id' => 'hoa_rules',
                'label' => 'HOA / Condominium Bylaws',
                'description' => 'HOA rules, bylaws, and financial reserve summary if applicable.',
                'required' => false,
            ],
        ];
    }

    /**
     * Helper to retrieve list of strictly required document type IDs for a listing.
     */
    public static function getRequiredDocumentTypes(Listing $listing): array
    {
        $instance = new self();
        $requirements = $instance->resolveRequirements($listing);
        return array_values(array_map(
            fn ($r) => $r['id'],
            array_filter($requirements, fn ($r) => !empty($r['required']))
        ));
    }

    public function index(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $requirements = $this->resolveRequirements($listing);
        $documents = $listing->documents()->get()->keyBy('type');

        $response = array_map(function ($req) use ($documents) {
            $uploaded = $documents->get($req['id']);
            return [
                'id' => $req['id'],
                'label' => $req['label'],
                'description' => $req['description'] ?? '',
                'required' => $req['required'],
                'status' => $uploaded ? 'uploaded' : 'pending',
                'fileName' => $uploaded ? $uploaded->original_name : null,
                'uploadedAt' => $uploaded ? $uploaded->created_at->toIso8601String() : null,
                'fileSize' => $uploaded ? $uploaded->size : null,
            ];
        }, $requirements);

        return api_success($response);
    }

    public function store(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $requirements = $this->resolveRequirements($listing);
        $allowedIds = array_column($requirements, 'id');

        $request->validate([
            'document_id' => ['required', 'string', Rule::in($allowedIds)],
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

        // Approved Rule: Editing/modifying a published listing unpublishes it until re-verified
        if ($listing->status === 'published') {
            $listing->update([
                'status' => 'in_progress',
                'moderation_status' => 'edited_pending_revalidation',
            ]);
        }

        return api_success([
            'id' => $document->type,
            'fileName' => $document->original_name,
            'status' => 'uploaded'
        ], 'Document uploaded.', 201);
    }

    public function download(Request $request, Listing $listing, $documentId)
    {
        Gate::authorize('view', $listing);

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
        Gate::authorize('update', $listing);

        $document = $listing->documents()->where('type', $documentId)->firstOrFail();

        if (Storage::disk('local')->exists($document->path)) {
            Storage::disk('local')->delete($document->path);
        }

        $document->delete();

        if ($listing->status === 'published') {
            $listing->update([
                'status' => 'in_progress',
                'moderation_status' => 'edited_pending_revalidation',
            ]);
        }

        return api_success(null, 'Document deleted.');
    }
}
