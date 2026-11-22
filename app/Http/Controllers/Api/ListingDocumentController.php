<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Services\DisclosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;

class ListingDocumentController extends Controller
{
    /**
     * Reozom Document Catalog mapping.
     */
    public const REOZOM_DOCUMENT_TYPES = [
        'sellers_disclosure'   => ['label' => "Seller's Disclosure Statement", 'category' => 'statutory', 'signable' => true],
        'lead_disclosure'      => ['label' => 'Lead-Based Paint Hazard Disclosure', 'category' => 'statutory', 'signable' => true],
        'listing_agreement'    => ['label' => 'Exclusive Right to Sell Listing Agreement', 'category' => 'statutory', 'signable' => true],
        'agency_disclosure'    => ['label' => 'Agency Disclosure Statement', 'category' => 'statutory', 'signable' => true],
        'deed'                 => ['label' => 'Property Deed / Title', 'category' => 'required', 'signable' => false],
        'id_proof'             => ['label' => 'Government Photo ID', 'category' => 'required', 'signable' => false],
        'appraisal'            => ['label' => 'Appraisal Report', 'category' => 'supplementary', 'signable' => false],
        'survey'               => ['label' => 'Property Survey', 'category' => 'supplementary', 'signable' => false],
        'home_warranty'        => ['label' => 'Home Warranty Policy', 'category' => 'supplementary', 'signable' => false],
        'floor_plan'           => ['label' => 'Floor Plan Architectural Drawing', 'category' => 'supplementary', 'signable' => false],
        'hoa_rules'            => ['label' => 'HOA / Condominium Bylaws', 'category' => 'supplementary', 'signable' => false],
        'tax_record'           => ['label' => 'Recent Property Tax Bill', 'category' => 'supplementary', 'signable' => false],
        'water_report'         => ['label' => 'Well Water Quality Test Report', 'category' => 'supplementary', 'signable' => false],
        'lead_paint_report'    => ['label' => 'Lead-Paint Inspection Report', 'category' => 'supplementary', 'signable' => false],
        'pre_approval_letter'  => ['label' => 'Mortgage Pre-Approval Letter', 'category' => 'supplementary', 'signable' => false],
        'closing_documents'    => ['label' => 'Closing Settlement Statements', 'category' => 'supplementary', 'signable' => false],
        'other'                => ['label' => 'Other Attachment', 'category' => 'supplementary', 'signable' => false],
        'lead_pamphlet'        => ['label' => 'Protect Your Family From Lead In Your Home', 'category' => 'supplementary', 'signable' => false],
        'addendum'             => ['label' => 'Addendum to Purchase Agreement', 'category' => 'supplementary', 'signable' => false],
        'condo_governance_form' => ['label' => 'Condo Governance Form', 'category' => 'supplementary', 'signable' => false],
    ];

    /**
     * Resolve document requirements for this listing.
     */
    private function resolveRequirements(Listing $listing): array
    {
        // Agent listings have no statutory documents to sign, only uploaded files (original Reozom)
        if ($listing->isAgentListing()) {
            app(DisclosureService::class)->provisionDocuments($listing);

            return $this->uploadRequirements();
        }

        if ($listing->usesDisclosureSteps()) {
            return $this->generatedRequirements($listing);
        }

        // Automatically ensure statutory documents are provisioned
        $discService = app(DisclosureService::class);
        $discService->regenerateStatutoryDocuments($listing);
        $selectedCodes = $discService->getSelectedDisclosureCodes($listing);

        $hasSellers = empty($selectedCodes)
            || in_array('mi_residential_appliances', $selectedCodes)
            || in_array('mi_residential_conditions', $selectedCodes)
            || in_array('mi_vacant_land_disclosure', $selectedCodes)
            || in_array('fl_residential_single_family', $selectedCodes)
            || in_array('fl_vacant_land_disclosure', $selectedCodes)
            || in_array('property_conditions', $selectedCodes);

        $hasLead = empty($selectedCodes) || in_array('lead_paint_disclosure', $selectedCodes) || in_array('lead_paint', $selectedCodes);
        $hasCondo = in_array('fl_residential_condo', $selectedCodes);

        $requirements = [];

        if ($hasSellers) {
            $requirements[] = [
                'id' => 'sellers_disclosure',
                'label' => "Seller's Disclosure Statement",
                'description' => 'Statutory property condition and improvement disclosure.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ];
        }

        if ($hasLead) {
            $requirements[] = [
                'id' => 'lead_disclosure',
                'label' => 'Lead-Based Paint Hazard Disclosure',
                'description' => 'Federal EPA & State statutory lead hazard disclosure.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ];
        }

        if ($hasCondo) {
            $requirements[] = [
                'id' => 'condo_disclosure',
                'label' => "Condominium Seller's Property Disclosure",
                'description' => 'Florida statutory condominium governance, reserve funding, and milestone inspection disclosure.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ];
        }

        // Brokerage agreements only once the service package is paid (Reozom "free listing")
        if (app(\App\Services\PaymentService::class)->listingIsPaid($listing)) {
            $requirements[] = [
                'id' => 'listing_agreement',
                'label' => 'Exclusive Right to Sell Listing Agreement',
                'description' => 'Exclusive brokerage listing agreement with package terms.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ];
            $requirements[] = [
                'id' => 'agency_disclosure',
                'label' => 'Agency Disclosure Statement',
                'description' => 'Real estate agency relationships and fiduciary duties disclosure.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ];
        }
        return array_merge($requirements, $this->uploadRequirements());
    }

    /**
     * Listings with disclosure steps: the documents generated from the document templates
     * (state + property type set and rules), then the files the seller uploads.
     */
    private function generatedRequirements(Listing $listing): array
    {
        $generated = app(DisclosureService::class)->generateListingDocuments($listing);

        $requirements = $generated->map(fn (ListingDocument $doc) => [
            'id' => $doc->type,
            'label' => $doc->template?->name ?? str_replace('.pdf', '', $doc->original_name),
            'description' => $doc->template?->source === \App\Models\DocumentTemplate::SOURCE_DISCLOSURE
                ? 'Generated from your disclosure answers.'
                : 'Generated from your listing and package details.',
            'required' => true,
            'category' => 'statutory',
            'signable' => (bool) ($doc->template?->requires_signature ?? true),
            'generated' => true,
        ])->all();

        return array_merge($requirements, $this->uploadRequirements());
    }

    /**
     * Files the seller uploads (not generated).
     */
    private function uploadRequirements(): array
    {
        return [
            [
                'id' => 'deed',
                'label' => 'Property Deed / Title',
                'description' => 'Recorded copy of deed verifying current legal ownership.',
                'required' => true,
                'category' => 'required',
                'signable' => false,
            ],
            [
                'id' => 'id_proof',
                'label' => 'Government Photo ID',
                'description' => 'Valid government-issued photo ID of the owner.',
                'required' => true,
                'category' => 'required',
                'signable' => false,
            ],
            [
                'id' => 'tax_record',
                'label' => 'Recent Property Tax Bill',
                'description' => 'Latest municipal property tax assessment or bill.',
                'required' => false,
                'category' => 'supplementary',
                'signable' => false,
            ],
            [
                'id' => 'hoa_rules',
                'label' => 'HOA / Condominium Bylaws',
                'description' => 'Bylaws, covenants, conditions, and restrictions.',
                'required' => false,
                'category' => 'supplementary',
                'signable' => false,
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
        app(DisclosureService::class)->attachExtraDocuments($listing);
        $documents = $listing->documents()->get();
        $docsByType = $documents->keyBy('type');

        // Form requirements list
        $response = array_map(function ($req) use ($docsByType) {
            $uploaded = $docsByType->get($req['id']);
            $isSignable = !empty($req['signable']);
            $status = 'pending';

            if ($uploaded) {
                if ($isSignable) {
                    $status = $uploaded->status === 'signed' ? 'signed' : 'unsigned';
                } else {
                    $status = 'uploaded';
                }
            }

            return [
                'id' => $req['id'],
                'documentId' => $uploaded ? $uploaded->id : null,
                'label' => $req['label'],
                'description' => $req['description'] ?? '',
                'required' => $req['required'],
                'category' => $req['category'] ?? 'required',
                'status' => $status,
                'verificationStatus' => $uploaded ? $uploaded->status : 'pending',
                'adminNotes' => $uploaded ? $uploaded->admin_notes : null,
                'reviewedAt' => $uploaded && $uploaded->reviewed_at ? $uploaded->reviewed_at->toIso8601String() : null,
                'signedAt' => $uploaded && $uploaded->signed_at ? $uploaded->signed_at->toIso8601String() : null,
                'signedBy' => $uploaded ? $uploaded->signed_by : null,
                'signatureImage' => $uploaded ? $uploaded->signature_image : null,
                'signatureType' => $uploaded ? $uploaded->signature_type : null,
                'verificationCode' => $uploaded ? $uploaded->verification_code : null,
                'isSignable' => $isSignable,
                'isPreviewable' => $isSignable,
                'isGenerated' => !empty($req['generated']),
                'fileName' => $uploaded ? $uploaded->original_name : null,
                'uploadedAt' => $uploaded ? $uploaded->created_at->toIso8601String() : null,
                'fileSize' => $uploaded ? $uploaded->size : null,
            ];
        }, $requirements);

        // Append any supplementary uploaded documents that are not in fixed requirements list
        $reqIds = array_column($requirements, 'id');
        foreach ($documents as $doc) {
            if (!in_array($doc->type, $reqIds, true)) {
                $response[] = [
                    'id' => $doc->id,
                    'documentId' => $doc->id,
                    'label' => self::REOZOM_DOCUMENT_TYPES[$doc->type]['label'] ?? ucfirst(str_replace('_', ' ', $doc->type)),
                    'description' => 'Supplementary listing documentation.',
                    'required' => false,
                    'category' => 'supplementary',
                    'status' => 'uploaded',
                    'verificationStatus' => $doc->status,
                    'adminNotes' => $doc->admin_notes,
                    'reviewedAt' => $doc->reviewed_at ? $doc->reviewed_at->toIso8601String() : null,
                    'signedAt' => $doc->signed_at ? $doc->signed_at->toIso8601String() : null,
                    'signedBy' => $doc->signed_by,
                    'isSignable' => false,
                    'isPreviewable' => false,
                    'fileName' => $doc->original_name,
                    'uploadedAt' => $doc->created_at->toIso8601String(),
                    'fileSize' => $doc->size,
                ];
            }
        }

        return api_success($response);
    }

    public function store(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        // Types the system adds itself (pamphlet, signed addendum, extra forms) are not upload choices
        $allowedTypes = array_values(array_diff(
            array_keys(self::REOZOM_DOCUMENT_TYPES),
            ['lead_pamphlet', 'addendum', ...DisclosureService::EXTRA_DOCUMENT_TYPES]
        ));

        $request->validate([
            'document_id' => ['required', 'string', Rule::in($allowedTypes)],
            'custom_name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'], // 10MB max
        ]);

        $file = $request->file('file');
        $type = $request->input('document_id');
        $customName = $request->input('custom_name');

        $path = $file->storeAs(
            "listings/{$listing->id}/documents",
            \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension(),
            'local'
        );

        if (!$path) {
            return api_error('Failed to store document.', 500);
        }

        // For statutory and required singular types, replace previous if exists
        $singularTypes = ['sellers_disclosure', 'lead_disclosure', 'listing_agreement', 'agency_disclosure', 'deed', 'id_proof'];
        if (in_array($type, $singularTypes, true)) {
            if ($existing = $listing->documents()->where('type', $type)->first()) {
                if (Storage::disk('local')->exists($existing->path)) {
                    Storage::disk('local')->delete($existing->path);
                }
                $existing->delete();
            }
        }

        $originalName = $customName ? ($customName . '.' . $file->getClientOriginalExtension()) : $file->getClientOriginalName();

        $document = $listing->documents()->create([
            'user_id' => $request->user()->id,
            'type' => $type,
            'original_name' => $originalName,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => 'pending_review',
        ]);

        if ($listing->status === 'published') {
            $listing->update([
                'status' => 'in_progress',
                'moderation_status' => 'edited_pending_revalidation',
            ]);
        }

        return api_success([
            'id' => $document->type,
            'documentId' => $document->id,
            'fileName' => $document->original_name,
            'status' => 'uploaded',
            'verificationStatus' => 'pending_review',
        ], 'Document uploaded.', 201);
    }

    /**
     * Find existing document or auto-provision statutory document.
     */
    private function findOrProvisionDocument(Listing $listing, string $documentId): ListingDocument
    {
        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->first();

        if (!$document) {
            app(DisclosureService::class)->provisionDocuments($listing);
            $document = $listing->documents()
                ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
                ->first();
        }

        if (!$document && isset(self::REOZOM_DOCUMENT_TYPES[$documentId])) {
            $def = self::REOZOM_DOCUMENT_TYPES[$documentId];
            $document = $listing->documents()->create([
                'user_id' => $listing->user_id,
                'type' => $documentId,
                'original_name' => "{$def['label']}.pdf",
                'path' => "listings/{$listing->id}/statutory/{$documentId}.html",
                'mime_type' => 'application/pdf',
                'size' => 1024 * 45,
                'status' => 'unsigned',
                'admin_notes' => 'Generated statutory listing document.',
            ]);
        }

        if (!$document) {
            abort(404, 'Document not found.');
        }

        return $document;
    }

    /**
     * Render and preview statutory listing disclosure or agreement.
     */
    public function preview(Request $request, Listing $listing, $documentId)
    {
        Gate::authorize('view', $listing);

        $document = $this->findOrProvisionDocument($listing, $documentId);

        $answers = app(DisclosureService::class)->getAnswers($listing);
        $html = $this->renderStatutoryHtml($listing, $document, $answers);

        if ($request->wantsJson()) {
            return api_success([
                'id' => $document->id,
                'type' => $document->type,
                'name' => $document->original_name,
                'status' => $document->status,
                'signedAt' => $document->signed_at ? $document->signed_at->toIso8601String() : null,
                'signedBy' => $document->signed_by,
                'html' => $html,
            ]);
        }

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * View a document inline in the browser (e.g. PDF viewer in a new tab).
     */
    public function view(Request $request, Listing $listing, $documentId)
    {
        return $this->serveDocument($request, $listing, $documentId, true);
    }

    /**
     * Download or view a document for a listing.
     */
    public function download(Request $request, Listing $listing, $documentId)
    {
        $inline = $request->boolean('inline') || $request->has('view');
        return $this->serveDocument($request, $listing, $documentId, $inline);
    }

    /**
     * Serve document either inline (for in-browser PDF viewing in new tab) or as attachment (direct download).
     */
    /**
     * Reozom emails the seller when someone else (agent, admin) opens one of their uploaded
     * documents. At most one email per viewer and listing per hour, and a mail failure never
     * blocks the download.
     */
    private function notifyOwnerOfView(Request $request, Listing $listing, ListingDocument $document): void
    {
        $viewer = $request->user();
        $owner = $listing->user;
        $isUploaded = $document->document_template_id === null
            && ! in_array($document->type, DisclosureService::STATUTORY_DOCUMENT_TYPES, true);

        if (! $isUploaded || ! $owner || ! $viewer || $viewer->id === $owner->id
            || ! \Illuminate\Support\Facades\Cache::add("documents-viewed:{$listing->id}:{$viewer->id}", true, now()->addHour())) {
            return;
        }

        try {
            $owner->notify(new \App\Notifications\DocumentsViewedNotification($listing, $viewer));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Documents-viewed email failed.', ['listing_id' => $listing->id, 'error' => $e->getMessage()]);
        }
    }

    protected function serveDocument(Request $request, Listing $listing, $documentId, bool $isInline)
    {
        Gate::authorize('view', $listing);

        $document = $this->findOrProvisionDocument($listing, $documentId);
        $this->notifyOwnerOfView($request, $listing, $document);

        $filename = $document->original_name;
        if (!str_ends_with(strtolower($filename), '.pdf') && !str_ends_with(strtolower($filename), '.html')) {
            $filename .= '.pdf';
        }

        $disposition = $isInline ? 'inline' : 'attachment';

        // Signed through EunoDoc: serve the signed packet EunoDoc returned, never a re-render.
        $signedPath = $this->eunoSignedPath($document);
        if ($signedPath !== null) {
            $fileContent = Storage::disk('local')->get($signedPath);

            return response($fileContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "{$disposition}; filename=\"Signed - {$filename}\"",
                'Content-Length' => strlen($fileContent),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        // Check for existing compiled PDF or file on private disk
        $candidates = array_filter([
            $document->path,
            str_replace('.html', '.pdf', $document->path),
            "listings/{$listing->id}/statutory/{$document->type}.pdf",
        ]);

        foreach ($candidates as $candidatePath) {
            if (Storage::disk('local')->exists($candidatePath)) {
                $fullPath = Storage::disk('local')->path($candidatePath);
                $mimeType = str_ends_with(strtolower($candidatePath), '.pdf')
                    ? 'application/pdf'
                    : ($document->mime_type ?: 'application/octet-stream');
                $fileContent = file_get_contents($fullPath);

                return response($fileContent, 200, [
                    'Content-Type' => $mimeType,
                    'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                    'Content-Length' => strlen($fileContent),
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                ]);
            }
        }

        // For statutory virtual documents, compile genuine PDF
        $answers = app(DisclosureService::class)->getAnswers($listing);
        $html = $this->renderStatutoryHtml($listing, $document, $answers);

        if (class_exists(Pdf::class)) {
            $pdfContent = $this->htmlToPdf($html);

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
                'Content-Length' => strlen($pdfContent),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => "{$disposition}; filename=\"" . str_replace('.pdf', '.html', $filename) . "\"",
        ]);
    }

    public function destroy(Request $request, Listing $listing, $documentId): JsonResponse
    {
        Gate::authorize('update', $listing);

        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->firstOrFail();

        // Generated documents are managed by the system (deleting would lose signatures and they are re-created anyway)
        if ($document->document_template_id !== null) {
            return api_error('Generated documents cannot be deleted. They are updated automatically from the listing answers.', 422);
        }
        // Reozom "Extra Documents" can be viewed and emailed, not removed
        if (in_array($document->type, DisclosureService::EXTRA_DOCUMENT_TYPES, true)) {
            return api_error('This form is provided with the listing and cannot be deleted.', 422);
        }

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

    /**
     * Rename an uploaded supplementary document.
     */
    public function rename(Request $request, Listing $listing, $documentId): JsonResponse
    {
        Gate::authorize('update', $listing);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->firstOrFail();

        $newName = $request->input('name');
        if (!str_ends_with(strtolower($newName), '.pdf') && str_ends_with(strtolower($document->original_name), '.pdf')) {
            $newName .= '.pdf';
        }

        $document->update([
            'original_name' => $newName,
        ]);

        return api_success($document, 'Document renamed successfully.');
    }

    /**
     * Send a listing document to one or more email addresses with an optional note.
     */
    public function sendToEmail(Request $request, Listing $listing, $documentId): JsonResponse
    {
        Gate::authorize('view', $listing);

        $request->validate([
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['required', 'email'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $document = $this->findOrProvisionDocument($listing, $documentId);
        $filePath = null;

        if (Storage::disk('local')->exists($document->path)) {
            $filePath = Storage::disk('local')->path($document->path);
        } else {
            // Statutory document: render PDF
            $answers = app(DisclosureService::class)->getAnswers($listing);
            $html = $this->renderStatutoryHtml($listing, $document, $answers);
            if (class_exists(Pdf::class)) {
                $pdfContent = $this->htmlToPdf($html);
                $tempRel = "listings/{$listing->id}/statutory/{$document->type}.pdf";
                Storage::disk('local')->put($tempRel, $pdfContent);
                $filePath = Storage::disk('local')->path($tempRel);
            }
        }

        if (!$filePath || !file_exists($filePath)) {
            return api_error('Document file not found or could not be generated.', 404);
        }

        $emails = $request->input('emails');
        $note = $request->input('note', '');
        $docName = $document->original_name ?: (self::REOZOM_DOCUMENT_TYPES[$document->type]['label'] ?? 'Listing Document');

        try {
            \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($emails, $filePath, $docName, $listing, $note) {
                $message->to($emails)
                    ->subject("Document: {$docName} - Listing #{$listing->id}")
                    ->html("
                        <p>Hello,</p>
                        <p>Attached is the document <strong>{$docName}</strong> for listing <strong>{$listing->address}, {$listing->city}, {$listing->state} {$listing->zip}</strong>.</p>
                        " . ($note ? "<p><strong>Note:</strong> " . nl2br(e($note)) . "</p>" : "") . "
                        <p>Regards,<br>Reozom Real Estate</p>
                    ")
                    ->attach($filePath, [
                        'as' => str_ends_with(strtolower($docName), '.pdf') ? $docName : ($docName . '.pdf'),
                        'mime' => 'application/pdf',
                    ]);
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send document email: ' . $e->getMessage());
        }

        return api_success([
            'sent_to' => $emails,
            'document' => $docName,
        ], 'Document has been sent to ' . implode(', ', $emails));
    }

    /**
     * Re-render a generated document from the current answers (signed documents whose
     * content changed go back to unsigned).
     */
    public function regenerate(Request $request, Listing $listing, $documentId): JsonResponse
    {
        Gate::authorize('update', $listing);

        $document = $listing->documents()
            ->where(fn ($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->whereNotNull('document_template_id')
            ->firstOrFail();

        $document = app(DisclosureService::class)->regenerateDocument($listing, $document);

        return api_success($document, 'Document regenerated from the current listing answers.');
    }

    /**
     * Admin review: approve or reject listing document.
     */
    public function review(Request $request, Listing $listing, $documentId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected,pending_review'],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->firstOrFail();

        $document->update([
            'status' => $validated['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        return api_success($document, "Document status updated to {$validated['status']}.");
    }

    /**
     * Admin download all documents for a listing as a ZIP archive.
     */
    public function downloadAll(Request $request, Listing $listing)
    {
        Gate::authorize('view', $listing);

        $documents = $listing->documents()->get();
        if ($documents->isEmpty()) {
            return api_error('No documents found for this listing.', 404);
        }

        $zipFileName = "Listing_{$listing->reference_code}_Documents.zip";
        $zipFilePath = storage_path("app/temp/{$zipFileName}");

        if (!file_exists(dirname($zipFilePath))) {
            mkdir(dirname($zipFilePath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return api_error('Failed to create ZIP archive.', 500);
        }

        $answers = app(DisclosureService::class)->getAnswers($listing);

        foreach ($documents as $doc) {
            if (Storage::disk('local')->exists($doc->path)) {
                $fileContent = Storage::disk('local')->get($doc->path);
                $zip->addFromString("{$doc->type}_{$doc->original_name}", $fileContent);
            } else {
                // Statutory virtual document - render genuine PDF
                $html = $this->renderStatutoryHtml($listing, $doc, $answers);
                $filename = $doc->original_name;
                if (!str_ends_with(strtolower($filename), '.pdf')) {
                    $filename .= '.pdf';
                }

                if (class_exists(Pdf::class)) {
                    $pdfContent = $this->htmlToPdf($html);
                    $zip->addFromString("{$doc->type}_{$filename}", $pdfContent);
                } else {
                    $zip->addFromString("{$doc->type}_{$doc->original_name}.html", $html);
                }
            }
        }

        $zip->close();

        return response()->download($zipFilePath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Render Reozom-style formatted statutory documents.
     */
    /**
     * @param  array<int, array{name: string, email: string, role: string}>|null  $offerSigners  purchase agreement
     *         signers (in signing order); when given, the document is rendered for the offer packet with
     *         buyer/seller lines numbered like the purchase agreement (original Reozom pdf.offers views)
     */
    private function renderStatutoryHtml(Listing $listing, ListingDocument $document, array $answers, ?array $offerSigners = null): string
    {
        $addressAns = $listing->answers()->where('step_id', 'step_property_type_address')->first();
        $addrVals = is_array($addressAns?->values) ? $addressAns->values : [];

        $street = $listing->address ?: ($addrVals['street_address'] ?? '14520 Michigan Ave');
        $city = $listing->city ?: ($addrVals['city'] ?? 'Dearborn');
        $state = method_exists($listing, 'getStateCode') ? $listing->getStateCode() : ($listing->state ?: 'MI');
        $zip = $listing->zip ?: ($addrVals['zip'] ?? '48126');
        $address = htmlspecialchars(trim("{$street}, {$city}, {$state} {$zip}"));

        $seller = htmlspecialchars($listing->user?->name ?? 'Property Owner');
        $refCode = htmlspecialchars($listing->reference_code);
        $date = now()->format('F j, Y');

        $signatureBlock = $offerSigners !== null
            ? $this->offerDisclosureSignatureHtml($offerSigners, self::isLeadDisclosure($document))
            : $this->signatureLinesHtml($listing->eSignSigners());

        if ($offerSigners === null && $document->status === 'signed' && $document->signed_at) {
            $signedOn = $document->signed_at->format('M d, Y');
            $signatureBlock .= "<p style=\"font-size:11px;color:#334155;margin-top:8px;\">Signed electronically via EunoDoc on {$signedOn}. The signed copy is available from the Documents page.</p>";
        }

        $docTitle = htmlspecialchars(str_replace('.pdf', '', $document->original_name));
        $packageName = htmlspecialchars($listing->servicePackage?->name ?? 'Reozom Flat-Fee MLS Package');

        // Document specific body content
        $bodyContent = '';
        if (!empty($document->content)) {
            // Generated from a document template (disclosure answers or agreement text)
            $bodyContent = $document->content;
        } elseif ($document->type === 'lead_disclosure') {
            $builtPre1978 = $this->formatAns($answers, 'built_before_1978', 'No (Constructed 1978 or Later)');
            $leadKnowledge = $this->formatAns($answers, 'lead_knowledge_id', 'Seller has no knowledge of lead-based paint and/or hazards in the housing.');
            $leadReports = $this->formatAns($answers, 'lead_reports_id', 'Seller has no reports or records pertaining to lead-based paint and/or hazards in the housing.');

            $bodyContent = <<<HTML
        <h2>Lead Warning Statement (Federal 42 U.S.C. 4852d)</h2>
        <p style="font-size:12px;color:#475569;line-height:1.6;">
            Every purchaser of any interest in residential real property on which a residential dwelling was built prior to 1978 is notified that such property may present exposure to lead from lead-based paint that may place young children at risk of developing lead poisoning. The seller of any interest in residential real property is required to provide the buyer with any information on lead-based paint hazards from risk assessments or inspections in the seller's possession and notify the buyer of any known lead-based paint hazards.
        </p>

        <h2>Seller's Statutory Disclosure</h2>
        <table class="checklist">
            <thead>
                <tr>
                    <th style="width:35%;">Statutory Requirement</th>
                    <th style="width:65%;">Seller's Disclosure & Certification</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Property Age Certification</strong></td>
                    <td>Was the property constructed prior to 1978? <strong>{$builtPre1978}</strong></td>
                </tr>
                <tr>
                    <td><strong>Known Lead Hazards</strong></td>
                    <td>{$leadKnowledge}</td>
                </tr>
                <tr>
                    <td><strong>Available Records & Reports</strong></td>
                    <td>{$leadReports}</td>
                </tr>
            </tbody>
        </table>

        <h2>Purchaser's Acknowledgment & Rights</h2>
        <p style="font-size:12px;color:#475569;line-height:1.5;">
            Purchaser has received copies of all information listed above and the EPA-approved lead hazard information pamphlet <em>"Protect Your Family From Lead In Your Home"</em>. Purchaser has received a 10-day opportunity (or mutually agreed period) to conduct a risk assessment or inspection for the presence of lead-based paint hazards.
        </p>
HTML;
        } elseif ($document->type === 'listing_agreement') {
            $bodyContent = <<<HTML
        <h2>Exclusive Right to Sell Listing Agreement Terms</h2>
        <p style="font-size:12px;color:#475569;line-height:1.6;">
            This Exclusive Right to Sell Listing Agreement is entered into between the Seller and Reozom Real Estate Brokerage. The Seller hereby grants the Brokerage the exclusive authority to market, advertise, and submit the listing to the Multiple Listing Service (MLS) according to the selected package specifications.
        </p>

        <table class="checklist">
            <thead>
                <tr>
                    <th style="width:35%;">Agreement Parameter</th>
                    <th style="width:65%;">Terms & Specifications</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Service Package</strong></td>
                    <td><strong>{$packageName}</strong></td>
                </tr>
                <tr>
                    <td><strong>Listing Address</strong></td>
                    <td>{$address}</td>
                </tr>
                <tr>
                    <td><strong>Seller / Property Owner</strong></td>
                    <td>{$seller}</td>
                </tr>
                <tr>
                    <td><strong>MLS Syndication</strong></td>
                    <td>Full syndication to local Board MLS, Realtor.com, Zillow, Redfin, and partner real estate networks.</td>
                </tr>
                <tr>
                    <td><strong>Brokerage Duties</strong></td>
                    <td>Brokerage shall furnish MLS data entry, forward buyer inquiries directly to seller, and provide statutory state-mandated disclosures.</td>
                </tr>
                <tr>
                    <td><strong>Seller Representations</strong></td>
                    <td>Seller warrants legal ownership of the property, authority to execute this agreement, and accuracy of all property details disclosed.</td>
                </tr>
            </tbody>
        </table>
HTML;
        } elseif ($document->type === 'agency_disclosure') {
            $bodyContent = <<<HTML
        <h2>Disclosure Regarding Real Estate Agency Relationships</h2>
        <p style="font-size:12px;color:#475569;line-height:1.6;">
            State law requires real estate licensees who are acting as agents of sellers or buyers of real property to advise the potential sellers or buyers with whom they work of the nature of their agency relationship and fiduciary responsibilities.
        </p>

        <table class="checklist">
            <thead>
                <tr>
                    <th style="width:30%;">Agency Classification</th>
                    <th style="width:70%;">Legal Duties and Obligations</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Seller's Agent</strong></td>
                    <td>An agent who acts solely on behalf of the seller owes the seller loyalty, confidentiality, full disclosure, obedience, and reasonable care. The agent must disclose all known material facts regarding the property to prospective buyers.</td>
                </tr>
                <tr>
                    <td><strong>Buyer's Agent</strong></td>
                    <td>An agent who acts solely on behalf of the buyer owes the buyer fiduciary duties including negotiating favorable terms and maintaining buyer confidentiality.</td>
                </tr>
                <tr>
                    <td><strong>Dual Agency</strong></td>
                    <td>A real estate licensee may act as agent for both seller and buyer only with the informed, written consent of both parties. In dual agency, the agent cannot advocate exclusively for either party.</td>
                </tr>
                <tr>
                    <td><strong>Reozom Limited Representation</strong></td>
                    <td>Under Reozom's flat-fee MLS arrangement, Reozom acts as the listing transaction facilitator providing entry and marketing services, while seller represents themselves in offer negotiations.</td>
                </tr>
            </tbody>
        </table>
HTML;
        } elseif ($document->type === 'condo_disclosure') {
            $bodyContent = <<<HTML
        <h2>Condominium Seller's Property Disclosure Statement</h2>
        <p style="font-size:12px;color:#475569;line-height:1.6;">
            This disclosure relates to the condominium parcel located at {$address}. The seller makes the following disclosures pursuant to state statutory condominium governance regulations.
        </p>

        <table class="checklist">
            <thead>
                <tr>
                    <th style="width:35%;">Condominium Category</th>
                    <th style="width:65%;">Disclosure Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>HOA / Condominium Association</strong></td>
                    <td>Governed by master deed, condominium declaration, and recorded bylaws.</td>
                </tr>
                <tr>
                    <td><strong>Common Elements & Reserves</strong></td>
                    <td>Seller warrants current status of monthly association assessments and reserve funding disclosures.</td>
                </tr>
                <tr>
                    <td><strong>Special Assessments & Milestone Inspections</strong></td>
                    <td>No unapproved or pending special assessments known to seller except as disclosed in association documentation.</td>
                </tr>
            </tbody>
        </table>
HTML;
        } else {
            // Standard Seller's Disclosure Statement (statutory conditions)
            $bodyContent = <<<HTML
        <h2>Statutory Property Condition Disclosures</h2>
        <p style="font-size:12px;color:#475569;line-height:1.5;">
            This disclosure statement is executed in compliance with statutory disclosure requirements. The seller discloses the following conditions and items based on actual knowledge as of the date signed.
        </p>

        <table class="checklist">
            <thead>
                <tr>
                    <th style="width:38%;">Item / System Category</th>
                    <th style="width:27%;">Seller Condition Disclosure</th>
                    <th style="width:35%;">Notes / Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Basement & Foundation</strong><br><span style="color:#64748b;font-size:11px;">Water seepage, sump pump, structural condition</span></td>
                    <td>{$this->formatAns($answers, 'basement_water_id', 'No evidence of water')}</td>
                    <td>{$this->formatAns($answers, 'basement_water_explain', 'None reported')}</td>
                </tr>
                <tr>
                    <td><strong>Roof System</strong><br><span style="color:#64748b;font-size:11px;">Leaks, shingles, approximate age</span></td>
                    <td>{$this->formatAns($answers, 'roof_leaks_id', 'No leaks')}</td>
                    <td>Age: {$this->formatAns($answers, 'roof_age', 'Standard')}</td>
                </tr>
                <tr>
                    <td><strong>Plumbing & Water System</strong><br><span style="color:#64748b;font-size:11px;">Pipes, water heater, water supply & sewer</span></td>
                    <td>{$this->formatAns($answers, 'plumbing_system', 'Good working order')}</td>
                    <td>Supply: {$this->formatAns($answers, 'water_types', 'Municipal')}</td>
                </tr>
                <tr>
                    <td><strong>Electrical & Heating / Cooling</strong><br><span style="color:#64748b;font-size:11px;">Service panel, furnace, central air conditioning</span></td>
                    <td>{$this->formatAns($answers, 'electrical_system', 'No known defects')}</td>
                    <td>HVAC: {$this->formatAns($answers, 'central_air_conditioning', 'Functional')}</td>
                </tr>
                <tr>
                    <td><strong>Appliances Included</strong><br><span style="color:#64748b;font-size:11px;">Range, refrigerator, dishwasher, washer, dryer</span></td>
                    <td>{$this->formatAns($answers, 'range_oven', 'Included')}</td>
                    <td>Refrig: {$this->formatAns($answers, 'refrigerator', 'Working')}</td>
                </tr>
                <tr>
                    <td><strong>Environmental & Lead Paint</strong><br><span style="color:#64748b;font-size:11px;">Radon, asbestos, mold, lead hazards</span></td>
                    <td>{$this->formatAns($answers, 'environmental_problems_id', 'No known problems')}</td>
                    <td>Lead: {$this->formatAns($answers, 'lead_knowledge_id', 'No known hazards')}</td>
                </tr>
            </tbody>
        </table>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{$docTitle} - {$refCode}</title>
    <style>
        /* Formal legal-document layout (US Letter). dompdf supports tables,
           fixed header/footer and page counters, but not flexbox/grid. */
        @page { margin: 0.6in 0.75in 0.75in 0.75in; }
        body { font-family: "Times New Roman", Times, serif; font-size: 10.5pt; line-height: 1.35; color: #111111; background: #ffffff; margin: 0; }
        .document-container { max-width: 7in; margin: 0 auto; }
        .letterhead { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0f6b5c; margin-bottom: 4px; }
        .letterhead td { vertical-align: middle; padding-bottom: 8px; }
        .brand { font-family: Helvetica, Arial, sans-serif; font-size: 15pt; font-weight: bold; letter-spacing: 3px; color: #0f6b5c; }
        .brand-sub { font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; letter-spacing: 1px; color: #555555; text-transform: uppercase; }
        .ref { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #333333; text-align: right; line-height: 1.5; }
        .doc-title { text-align: center; margin: 14px 0 2px 0; font-size: 14pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-subtitle { text-align: center; font-size: 9pt; font-style: italic; color: #444444; margin-bottom: 12px; }
        .parties { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .parties td { border: 1px solid #999999; padding: 5px 8px; font-size: 10pt; vertical-align: top; }
        .parties .label { width: 22%; background: #f2f2f2; font-weight: bold; }
        .doc-body h2 { font-size: 10.5pt; font-weight: bold; text-transform: uppercase; margin: 14px 0 5px 0; padding-bottom: 2px; border-bottom: 1px solid #111111; }
        .doc-body p { font-size: 10.5pt !important; color: #111111 !important; text-align: justify; margin: 0 0 6px 0; line-height: 1.35 !important; }
        .checklist { width: 100%; border-collapse: collapse; margin: 8px 0 14px 0; }
        .checklist th, .checklist td { border: 1px solid #777777; padding: 4px 6px; font-size: 9.5pt; text-align: left; vertical-align: top; }
        .checklist th { background: #e8e8e8; font-weight: bold; color: #111111; }
        .certification { margin-top: 16px; font-size: 10pt; font-style: italic; page-break-inside: avoid; }
        .sign-box { margin-top: 8px; page-break-inside: avoid; }
        .legal-notice { font-size: 8pt; color: #333333; margin-top: 14px; line-height: 1.35; border-top: 1px solid #999999; padding-top: 6px; text-align: justify; }
        .page-footer { position: fixed; bottom: -0.5in; left: 0; right: 0; height: 0.3in; font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; color: #555555; border-top: 0.5px solid #999999; padding-top: 4px; }
        .page-footer table { width: 100%; border-collapse: collapse; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <!-- no-print -->
    <div class="no-print" style="max-width:7in;margin:12px auto;text-align:right;">
        <button onclick="window.print()" style="background:#0f6b5c;color:#fff;border:none;padding:7px 14px;border-radius:4px;font-size:12px;cursor:pointer;">Print Document</button>
    </div>
    <!-- /no-print -->

    <div class="page-footer">
        <table><tr>
            <td>REOZOM &middot; Ref. {$refCode} &middot; {$docTitle}</td>
            <td style="text-align:right;">&nbsp;</td>
        </tr></table>
    </div>

    <div class="document-container">
        <table class="letterhead">
            <tr>
                <td>
                    <div class="brand">REOZOM</div>
                    <div class="brand-sub">Real Estate Transaction Platform</div>
                </td>
                <td class="ref">
                    Reference No. <strong>{$refCode}</strong><br>
                    Date: {$date}
                </td>
            </tr>
        </table>

        <div class="doc-title">{$docTitle}</div>
        <div class="doc-subtitle">State of {$state} &mdash; Statutory Real Estate Disclosure Document</div>

        <table class="parties">
            <tr>
                <td class="label">Property</td>
                <td colspan="3">{$address}</td>
            </tr>
            <tr>
                <td class="label">Seller / Owner</td>
                <td style="width:35%;">{$seller}</td>
                <td class="label">Service Package</td>
                <td>{$packageName}</td>
            </tr>
        </table>

        <div class="doc-body">
            {$bodyContent}
        </div>

        <div class="certification">
            By signing below, the Seller certifies that the information in this document is true and correct to the best of the Seller's knowledge as of the date signed.
        </div>
        <div class="sign-box">
            {$signatureBlock}
        </div>

        <div class="legal-notice">
            <strong>Notice:</strong> This document is generated through the Reozom Platform as an electronic statutory record. Prospective purchasers may rely upon this disclosure in making an offer. In accordance with state law, the Seller must promptly amend this disclosure in writing if any material change occurs prior to closing.
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Reozom's EunoDoc signature lines: one line per signer, two per row, each carrying a
     * white "$sN" tag. EunoService finds those tags in the PDF and places signer N's field there.
     *
     * @param  array<int, array{name: string, email: string}>  $signers
     */
    private function signatureLinesHtml(array $signers): string
    {
        $rows = '';
        foreach (array_chunk($signers, 2) as $rowIndex => $pair) {
            $cells = [];
            foreach ($pair as $i => $signer) {
                $cells[] = self::signatureCellHtml($rowIndex * 2 + $i + 1, 'Seller Signature', $signer);
            }
            $rows .= '<tr>'.implode('<td style="width:12%;"></td>', $cells).'</tr>'
                .'<tr><td colspan="3" style="height:18px;"></td></tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-top:12px;">'.$rows.'</table>';
    }

    /**
     * One signature cell. EunoDoc places signer N's field at the white "$sN" tag and draws it
     * downward (signature + signed-date stamp), so the tag sits above an empty signing space
     * that ends on the signature line; the role, name and date note stay clear below the line.
     *
     * @param  array{name: string, email: string}  $signer
     */
    public static function signatureCellHtml(int $number, string $role, array $signer): string
    {
        $pad = str_repeat('&nbsp;', 12);
        $name = htmlspecialchars($signer['name']).' ('.htmlspecialchars($signer['email']).')';

        return '<td style="width:44%;font-size:9.5pt;color:#111111;vertical-align:bottom;">'
            .'<p style="margin:0;text-align:center;color:#ffffff;font-size:25px;line-height:30px;">'
            ."{$pad}\$s{$number}{$pad}</p>"
            .'<div style="height:60px;border-bottom:0.5px solid #000;"></div>'
            .'<div style="margin-top:3px;font-size:7.5pt;color:#555555;text-transform:uppercase;letter-spacing:0.5px;">'.htmlspecialchars($role).'</div>'
            ."<div style=\"margin-top:2px;\"><strong>{$name}</strong></div>"
            .'<div style="margin-top:2px;font-size:7.5pt;color:#777777;">Date is added automatically when signed</div>'
            .'</td>';
    }

    /**
     * Stored EunoDoc signed packet for a document signed through EunoDoc, if it exists.
     */
    private function eunoSignedPath(ListingDocument $document): ?string
    {
        if ($document->status !== 'signed' || ($document->signature_type ?? null) !== 'eunodoc') {
            return null;
        }

        // This document's own signed pages (packet split after signing), as in Reozom
        $own = $document->audit_trail['signed_file_path'] ?? null;
        if ($own && Storage::disk('local')->exists($own)) {
            return $own;
        }

        $agreementId = $document->audit_trail['listing_euno_agreement_id'] ?? null;
        $path = $agreementId ? \App\Models\ListingEunoAgreement::find($agreementId)?->signed_file_path : null;

        return $path && Storage::disk('local')->exists($path) ? $path : null;
    }

    /**
     * Unsigned PDF of a generated document, as sent to EunoDoc for signing.
     */
    /**
     * @param  array<int, array{name: string, email: string, role: string}>|null  $offerSigners  see renderStatutoryHtml()
     */
    public function renderPdf(Listing $listing, ListingDocument $document, ?array $offerSigners = null): string
    {
        $answers = app(DisclosureService::class)->getAnswers($listing);
        $html = $this->renderStatutoryHtml($listing, $document, $answers, $offerSigners);

        return $this->htmlToPdf($html);
    }

    public static function isLeadDisclosure(ListingDocument $document): bool
    {
        return $document->type === 'lead_disclosure' || str_ends_with((string) $document->type, '_lead');
    }

    /**
     * Seller's/Lead disclosure documents of the listing that go into the purchase agreement packet
     * (original Reozom adds the Lead and Seller's Disclosure to the PA): disclosure-sourced generated
     * documents, or the legacy sellers/lead/condo statutory documents.
     */
    public function offerDisclosureDocuments(Listing $listing): \Illuminate\Support\Collection
    {
        app(DisclosureService::class)->provisionDocuments($listing);

        return $listing->documents()->with('template')->get()
            ->filter(fn (ListingDocument $doc) => $doc->template
                ? $doc->template->source === \App\Models\DocumentTemplate::SOURCE_DISCLOSURE
                : in_array($doc->type, ['sellers_disclosure', 'lead_disclosure', 'condo_disclosure'], true))
            ->values();
    }

    /**
     * Offer-packet signature area, as in Reozom's pdf.offers views: only buyers and sellers sign the
     * disclosures, each keeping their purchase-agreement number ($sN). Rows pair Buyer N with Seller N.
     * The Lead Disclosure also gets the acknowledgement initials ($iN) of every seller and buyer.
     *
     * @param  array<int, array{name: string, email: string, role: string}>  $signers  in purchase agreement order
     */
    private function offerDisclosureSignatureHtml(array $signers, bool $isLead): string
    {
        $buyers = [];
        $sellers = [];
        foreach (array_values($signers) as $index => $signer) {
            $entry = $signer + ['number' => $index + 1];
            if (($signer['role'] ?? '') === 'buyer') {
                $buyers[] = $entry;
            } elseif (in_array($signer['role'] ?? '', ['seller', 'additional_signer'], true)) {
                $sellers[] = $entry;
            }
        }

        $cell = function (?array $signer, string $label): string {
            return $signer
                ? self::signatureCellHtml($signer['number'], $label, $signer)
                : '<td style="width:44%;"></td>';
        };

        $html = '';
        if ($isLead) {
            $initials = function (array $people): string {
                // Like signatures, EunoDoc draws the initials field downward from the white "$iN"
                // tag, so the tag sits above an empty box that ends on the initials line.
                return implode('', array_map(
                    fn ($p) => '<div style="display:inline-block;width:70px;margin-right:8px;vertical-align:bottom;text-align:center;">'
                        .'<div style="color:#ffffff;font-size:16px;line-height:18px;">&nbsp;&nbsp;$i'.$p['number'].'&nbsp;&nbsp;</div>'
                        .'<div style="height:34px;border-bottom:0.5px solid #000;"></div>'
                        .'<div style="font-size:7pt;color:#777777;text-transform:uppercase;">Initials</div>'
                        .'</div>',
                    $people
                ));
            };
            $rows = [
                ['Seller has disclosed all known lead-based paint and/or lead-based paint hazards and provided all available records and reports.', $sellers],
                ['Purchaser has received copies of all information listed above.', $buyers],
                ['Purchaser has received the pamphlet "Protect Your Family From Lead In Your Home".', $buyers],
                ['Purchaser has received a 10-day opportunity (or mutually agreed period) to conduct a risk assessment or inspection, or has waived it.', $buyers],
            ];
            $html .= '<h2>Acknowledgements (initials)</h2><table style="width:100%;border-collapse:collapse;font-size:10pt;margin-bottom:12px;">';
            foreach ($rows as [$text, $people]) {
                if ($people === []) {
                    continue;
                }
                $html .= '<tr><td style="padding:6px 8px 6px 0;width:42%;vertical-align:bottom;">'.$initials($people).'</td>'
                    .'<td style="padding:6px 0;color:#334155;">'.htmlspecialchars($text).'</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= '<table style="width:100%;border-collapse:collapse;margin-top:12px;">';
        for ($i = 0, $n = max(count($buyers), count($sellers)); $i < $n; $i++) {
            $html .= '<tr>'.$cell($buyers[$i] ?? null, 'Buyer Signature').'<td style="width:12%;"></td>'
                .$cell($sellers[$i] ?? null, 'Seller Signature').'</tr>'
                .'<tr><td colspan="3" style="height:18px;"></td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * One place that turns a generated document into a US Letter PDF: drops the
     * browser-only print bar (dompdf ignores @media print) and stamps
     * "Page X of Y" on every page (dompdf doesn't support counter(pages)).
     */
    private function htmlToPdf(string $html): string
    {
        $html = preg_replace('#<!--\s*no-print\s*-->.*?<!--\s*/no-print\s*-->#s', '', $html) ?? $html;

        $pdf = Pdf::loadHTML($html)->setPaper('letter', 'portrait')->setWarnings(false);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        // Letter = 612 x 792 pt; right-aligned inside the page footer line.
        $canvas->page_text(500, 757, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.33, 0.33, 0.33]);

        return $dompdf->output();
    }

    /**
     * Generated documents of this listing that must be e-signed.
     *
     * @return \Illuminate\Support\Collection<int, ListingDocument>
     */
    public function signableDocuments(Listing $listing): \Illuminate\Support\Collection
    {
        $types = array_column(
            array_filter($this->resolveRequirements($listing), fn ($r) => ! empty($r['signable'])),
            'id'
        );

        return collect($types)->map(fn (string $type) => $this->findOrProvisionDocument($listing, $type));
    }

    private function formatAns(array $answers, string $key, string $default): string
    {
        // Check in combined or nested answers
        if (isset($answers[$key])) {
            return htmlspecialchars(ucfirst((string) $answers[$key]));
        }

        foreach ($answers as $sub) {
            if (is_array($sub) && isset($sub[$key])) {
                return htmlspecialchars(ucfirst((string) $sub[$key]));
            }
        }

        return htmlspecialchars($default);
    }
}
