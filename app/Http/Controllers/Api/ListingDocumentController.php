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
use Illuminate\Validation\Rule;

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
    ];

    /**
     * Resolve document requirements for this listing.
     */
    private function resolveRequirements(Listing $listing): array
    {
        // Automatically ensure statutory documents are provisioned
        app(DisclosureService::class)->regenerateStatutoryDocuments($listing);

        return [
            [
                'id' => 'sellers_disclosure',
                'label' => "Seller's Disclosure Statement",
                'description' => 'Statutory property condition and improvement disclosure.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ],
            [
                'id' => 'lead_disclosure',
                'label' => 'Lead-Based Paint Hazard Disclosure',
                'description' => 'Federal EPA & State statutory lead hazard disclosure.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ],
            [
                'id' => 'listing_agreement',
                'label' => 'Exclusive Right to Sell Listing Agreement',
                'description' => 'Exclusive brokerage listing agreement with package terms.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ],
            [
                'id' => 'agency_disclosure',
                'label' => 'Agency Disclosure Statement',
                'description' => 'Real estate agency relationships and fiduciary duties disclosure.',
                'required' => true,
                'category' => 'statutory',
                'signable' => true,
            ],
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
                'isSignable' => $isSignable,
                'isPreviewable' => $isSignable,
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

        $allowedTypes = array_keys(self::REOZOM_DOCUMENT_TYPES);

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
     * E-Sign a statutory listing document.
     */
    public function sign(Request $request, Listing $listing, $documentId): JsonResponse
    {
        Gate::authorize('update', $listing);

        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->firstOrFail();

        $signerName = $request->input('signer_name') ?: $request->user()->name;

        $document->update([
            'status' => 'signed',
            'signed_at' => now(),
            'signed_by' => $signerName,
        ]);

        return api_success([
            'id' => $document->id,
            'type' => $document->type,
            'status' => 'signed',
            'signedAt' => $document->signed_at->toIso8601String(),
            'signedBy' => $document->signed_by,
        ], 'Document successfully signed.');
    }

    /**
     * Render and preview statutory listing disclosure or agreement.
     */
    public function preview(Request $request, Listing $listing, $documentId)
    {
        Gate::authorize('view', $listing);

        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->firstOrFail();

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

    public function download(Request $request, Listing $listing, $documentId)
    {
        Gate::authorize('view', $listing);

        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->firstOrFail();

        // If file exists on private disk, stream download
        if (Storage::disk('local')->exists($document->path)) {
            return Storage::disk('local')->download(
                $document->path,
                $document->original_name,
                ['Content-Type' => $document->mime_type]
            );
        }

        // If it's a generated statutory document, render HTML as downloadable file
        $answers = app(DisclosureService::class)->getAnswers($listing);
        $html = $this->renderStatutoryHtml($listing, $document, $answers);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'inline; filename="' . $document->original_name . '"',
        ]);
    }

    public function destroy(Request $request, Listing $listing, $documentId): JsonResponse
    {
        Gate::authorize('update', $listing);

        $document = $listing->documents()
            ->where(fn($q) => $q->where('id', $documentId)->orWhere('type', $documentId))
            ->firstOrFail();

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
                // Statutory virtual document
                $html = $this->renderStatutoryHtml($listing, $doc, $answers);
                $zip->addFromString("{$doc->type}_{$doc->original_name}.html", $html);
            }
        }

        $zip->close();

        return response()->download($zipFilePath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Render Reozom-style formatted statutory documents.
     */
    private function renderStatutoryHtml(Listing $listing, ListingDocument $document, array $answers): string
    {
        $address = htmlspecialchars("{$listing->address}, {$listing->city}, {$listing->state} {$listing->zip}");
        $seller = htmlspecialchars($listing->user?->name ?? 'Property Owner');
        $refCode = htmlspecialchars($listing->reference_code);
        $date = now()->format('F j, Y');

        $isSigned = $document->status === 'signed';
        $signedBy = htmlspecialchars($document->signed_by ?? $seller);
        $signedAt = $document->signed_at ? $document->signed_at->format('M d, Y h:i A') : 'Pending Signature';

        $signatureBadge = $isSigned
            ? "<div style='display:inline-block;background:#ecfdf5;border:1px solid #10b981;color:#047857;padding:6px 14px;border-radius:6px;font-weight:600;'>âœ“ DIGITALLY SIGNED by {$signedBy} on {$signedAt}</div>"
            : "<div style='display:inline-block;background:#fffbeb;border:1px solid #f59e0b;color:#b45309;padding:6px 14px;border-radius:6px;font-weight:600;'>âš  NOT YET SIGNED</div>";

        $docTitle = htmlspecialchars($document->original_name);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{$docTitle} - {$refCode}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1e293b; background: #f8fafc; padding: 24px; }
        .document-container { max-width: 820px; margin: 0 auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 40px; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: flex-end; }
        .header h1 { margin: 0; font-size: 22px; color: #0f766e; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; background: #f1f5f9; border-radius: 6px; overflow: hidden; }
        .meta-table td { padding: 10px 14px; font-size: 13px; border-bottom: 1px solid #e2e8f0; }
        .meta-table td strong { color: #334155; }
        h2 { font-size: 15px; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; margin-top: 24px; color: #0f172a; text-transform: uppercase; }
        p { font-size: 13px; color: #475569; }
        .checklist { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .checklist th, .checklist td { border: 1px solid #cbd5e1; padding: 8px 12px; font-size: 12px; text-align: left; }
        .checklist th { background: #f8fafc; font-weight: 600; color: #334155; }
        .sign-box { margin-top: 36px; padding: 20px; border: 2px dashed #94a3b8; border-radius: 8px; background: #f8fafc; }
        .legal-notice { font-size: 11px; color: #64748b; margin-top: 24px; line-height: 1.4; border-top: 1px solid #e2e8f0; padding-top: 12px; }
        @media print {
            body { background: #fff; padding: 0; }
            .document-container { border: none; box-shadow: none; padding: 0; max-width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="max-width:820px;margin:0 auto 16px auto;display:flex;justify-content:flex-end;gap:12px;">
        <button onclick="window.print()" style="background:#0f766e;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;">Print Document</button>
    </div>

    <div class="document-container">
        <div class="header">
            <div>
                <h1>{$docTitle}</h1>
                <div style="font-size:12px;color:#64748b;margin-top:4px;">State of {$listing->state} Statutory Compliance Document</div>
            </div>
            <div style="text-align:right;">
                <span style="font-family:monospace;font-weight:bold;color:#0f766e;font-size:14px;">{$refCode}</span>
                <div style="font-size:11px;color:#94a3b8;">{$date}</div>
            </div>
        </div>

        <table class="meta-table">
            <tr>
                <td><strong>Property Address:</strong> {$address}</td>
                <td><strong>Owner / Seller:</strong> {$seller}</td>
            </tr>
            <tr>
                <td><strong>Reference Code:</strong> {$refCode}</td>
                <td><strong>Listing Status:</strong> {$listing->status}</td>
            </tr>
        </table>

        <h2>Statutory Disclosure Specifications</h2>
        <p>This disclosure statement is executed in compliance with statutory disclosure requirements. The seller discloses the following conditions and items based on actual knowledge as of the date signed.</p>

        <table class="checklist">
            <thead>
                <tr>
                    <th style="width:45%;">Item / System Category</th>
                    <th style="width:25%;">Seller Condition Disclosure</th>
                    <th style="width:30%;">Notes / Details</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Basement & Foundation</strong><br><span style="color:#64748b;">Evidence of water seepage / crawlspace issues</span></td>
                    <td>{$this->formatAns($answers, 'basement_water_id', 'No evidence of water')}</td>
                    <td>{$this->formatAns($answers, 'basement_water_explain', 'None reported')}</td>
                </tr>
                <tr>
                    <td><strong>Roof System</strong><br><span style="color:#64748b;">Leaks, age, and condition</span></td>
                    <td>{$this->formatAns($answers, 'roof_leaks_id', 'No leaks')}</td>
                    <td>Age: {$this->formatAns($answers, 'roof_age', 'Standard')}</td>
                </tr>
                <tr>
                    <td><strong>Insulation</strong><br><span style="color:#64748b;">Type & UFFI check</span></td>
                    <td>{$this->formatAns($answers, 'installed_uffi_id', 'No UFFI')}</td>
                    <td>{$this->formatAns($answers, 'insulation', 'Adequate')}</td>
                </tr>
                <tr>
                    <td><strong>Plumbing & Well / Septic</strong><br><span style="color:#64748b;">Supply, drainage, and private systems</span></td>
                    <td>{$this->formatAns($answers, 'plumbing_system', 'Good working order')}</td>
                    <td>{$this->formatAns($answers, 'well_septic_id', 'Municipal / Functional')}</td>
                </tr>
                <tr>
                    <td><strong>Electrical & Heating</strong><br><span style="color:#64748b;">Wiring, service panel, and furnace</span></td>
                    <td>{$this->formatAns($answers, 'electrical_system', 'No known defects')}</td>
                    <td>{$this->formatAns($answers, 'heating_system', 'Operational')}</td>
                </tr>
                <tr>
                    <td><strong>Lead-Based Paint Hazard</strong><br><span style="color:#64748b;">Federal 42 U.S.C. 4852d Disclosure</span></td>
                    <td>{$this->formatAns($answers, 'built_before_1978', 'Post-1978 / No knowledge')}</td>
                    <td>{$this->formatAns($answers, 'lead_knowledge_id', 'No known hazards present')}</td>
                </tr>
            </tbody>
        </table>

        <div class="sign-box">
            <h3 style="margin:0 0 12px 0;font-size:14px;color:#1e293b;">Electronic Signature & Execution Verification</h3>
            {$signatureBadge}
            <div style="margin-top:12px;font-size:12px;color:#64748b;">
                By signing, the seller certifies that the information provided in this disclosure is true and correct to the best of their knowledge.
            </div>
        </div>

        <div class="legal-notice">
            <strong>Legal Notice:</strong> This document is generated through the Reozom Platform as an electronic statutory record. The seller acknowledges that prospective purchasers may rely upon this disclosure in making an offer. In accordance with state law, the seller must promptly amend this disclosure in writing if any material change occurs prior to closing.
        </div>
    </div>
</body>
</html>
HTML;
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
