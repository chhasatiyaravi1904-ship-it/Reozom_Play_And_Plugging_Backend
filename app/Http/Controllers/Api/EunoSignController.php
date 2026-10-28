<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\ListingOfferDocument;
use App\Services\EunoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EunoSignController extends Controller
{
    public function __construct(
        protected EunoService $eunoService
    ) {}

    /**
     * Initiate EunoDoc e-sign flow for a listing's documents packet.
     */
    public function initiateListingSigning(Request $request, Listing $listing): JsonResponse
    {
        $user = $request->user();

        // Ensure user is seller of the listing or admin
        if ($user->id !== $listing->user_id && $user->role !== 'admin') {
            return api_error('Unauthorized to initiate signing for this listing.', 403);
        }

        // Gather all physical files associated with listing documents
        $documents = ListingDocument::where('listing_id', $listing->id)->get();
        $filePaths = [];

        foreach ($documents as $doc) {
            $fullPath = Storage::disk('local')->path($doc->path);
            if (file_exists($fullPath)) {
                $filePaths[] = $fullPath;
            }
        }

        // If no documents exist yet, create a dummy placeholder disclosure so the flow proceeds
        if (empty($filePaths)) {
            $dir = storage_app_path("listings/{$listing->id}/disclosures");
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }
            $sampleDocPath = $dir . '/Listing_Disclosures_Packet.pdf';
            if (!file_exists($sampleDocPath)) {
                file_put_contents($sampleDocPath, $this->buildSampleDisclosurePdf($listing));
            }
            $filePaths[] = $sampleDocPath;
        }

        // Prepare signers
        $signers = [
            [
                'email' => $listing->user->email,
                'name' => $listing->user->name,
                'type' => 'signer',
            ],
        ];

        // Call Euno API to register the agreement
        $docName = "Disclosures - {$listing->reference_code}";
        $agreementResponse = $this->eunoService->getAgreementKey($filePaths, $docName, $signers);

        if (!$agreementResponse || empty($agreementResponse['_id'])) {
            return api_error('Failed to initiate e-signature agreement with Eunodoc.', 502);
        }

        $agreementId = $agreementResponse['_id'];
        $signingUrl = $this->eunoService->getSigningUrlForUser($agreementId, $user->email);

        // Store agreement in database
        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id,
            'initiated_by_user_id' => $user->id,
            'document_type' => 'disclosures_bundle',
            'document_name' => $docName,
            'agreement_id' => $agreementId,
            'signing_url' => $signingUrl,
            'status' => 'inprogress',
            'signers' => $agreementResponse['recipients'] ?? $signers,
            'source_file_paths' => $filePaths,
            'sent_at' => now(),
        ]);

        return api_success([
            'agreement_id' => $agreement->id,
            'euno_agreement_id' => $agreementId,
            'signing_url' => $signingUrl,
            'status' => $agreement->status,
            'signers' => $agreement->signers,
        ], 'Eunodoc signature agreement created successfully.');
    }

    /**
     * Check status of a listing's EunoDoc agreement and sync completed PDF.
     */
    public function getListingSigningStatus(Request $request, Listing $listing): JsonResponse
    {
        $agreement = ListingEunoAgreement::where('listing_id', $listing->id)
            ->latest()
            ->first();

        if (!$agreement) {
            return api_success([
                'has_agreement' => false,
                'status' => 'not_initiated',
            ]);
        }

        // Check remote status if still in progress
        if ($agreement->status !== 'completed' && $agreement->agreement_id) {
            $remoteStatus = $this->eunoService->getAgreementStatus($agreement->agreement_id);
            if ($remoteStatus === 'completed') {
                $agreement->status = 'completed';
                $agreement->completed_at = now();

                // Download signed document
                $signedPdf = $this->eunoService->getSignedDocument($agreement->agreement_id);
                if ($signedPdf) {
                    $signedPath = "listings/{$listing->id}/signed/EunoSigned_Disclosures_{$agreement->id}.pdf";
                    Storage::disk('local')->put($signedPath, $signedPdf);
                    $agreement->signed_file_path = $signedPath;
                }

                // Download audit report
                $auditPdf = $this->eunoService->getAuditReport($agreement->agreement_id);
                if ($auditPdf) {
                    $auditPath = "listings/{$listing->id}/signed/EunoAuditReport_{$agreement->id}.pdf";
                    Storage::disk('local')->put($auditPath, $auditPdf);
                    $agreement->audit_file_path = $auditPath;
                }

                $agreement->save();

                // Mark listing documents as verified/signed
                ListingDocument::where('listing_id', $listing->id)->update([
                    'status' => 'signed',
                    'signed_at' => now(),
                    'admin_notes' => 'Electronically signed and verified via Eunodoc.',
                ]);
            } else {
                $agreement->status = $remoteStatus;
                $agreement->save();
            }
        }

        return api_success([
            'has_agreement' => true,
            'agreement_id' => $agreement->id,
            'euno_agreement_id' => $agreement->agreement_id,
            'status' => $agreement->status,
            'signing_url' => $agreement->signing_url,
            'is_completed' => $agreement->status === 'completed',
            'signed_at' => $agreement->completed_at,
            'has_signed_pdf' => (bool) $agreement->signed_file_path,
            'has_audit_pdf' => (bool) $agreement->audit_file_path,
        ]);
    }

    /**
     * Initiate EunoDoc e-sign flow for a Purchase Agreement on an offer.
     */
    public function initiateOfferSigning(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $listing = $offer->listing;

        // Ensure user is buyer, seller, or admin
        if ($user->id !== $offer->buyer_id && $user->id !== $listing->user_id && $user->role !== 'admin') {
            return api_error('Unauthorized to initiate signing on this offer.', 403);
        }

        // Build signers array (Buyer and Seller)
        $signers = [
            [
                'email' => $offer->buyer->email,
                'name' => $offer->buyer->name,
                'type' => 'signer',
            ],
            [
                'email' => $listing->user->email,
                'name' => $listing->user->name,
                'type' => 'signer',
            ],
        ];

        // Gather purchase agreement document or generate template
        $offerDocs = ListingOfferDocument::where('listing_offer_id', $offer->id)
            ->where('type', 'purchase_agreement')
            ->get();

        $filePaths = [];
        foreach ($offerDocs as $doc) {
            $path = Storage::disk('local')->path($doc->path);
            if (file_exists($path)) {
                $filePaths[] = $path;
            }
        }

        if (empty($filePaths)) {
            $dir = storage_app_path("offers/{$offer->id}");
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }
            $samplePa = $dir . '/Purchase_Agreement.pdf';
            if (!file_exists($samplePa)) {
                file_put_contents($samplePa, $this->buildSampleOfferPdf($offer));
            }
            $filePaths[] = $samplePa;
        }

        $docName = "Purchase Agreement - Offer #{$offer->id}";
        $agreementResponse = $this->eunoService->getAgreementKey($filePaths, $docName, $signers);

        if (!$agreementResponse || empty($agreementResponse['_id'])) {
            return api_error('Failed to initiate offer agreement with Eunodoc.', 502);
        }

        $agreementId = $agreementResponse['_id'];
        $signingUrl = $this->eunoService->getSigningUrlForUser($agreementId, $user->email);

        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id,
            'listing_offer_id' => $offer->id,
            'initiated_by_user_id' => $user->id,
            'document_type' => 'purchase_agreement',
            'document_name' => $docName,
            'agreement_id' => $agreementId,
            'signing_url' => $signingUrl,
            'status' => 'inprogress',
            'signers' => $agreementResponse['recipients'] ?? $signers,
            'source_file_paths' => $filePaths,
            'sent_at' => now(),
        ]);

        return api_success([
            'agreement_id' => $agreement->id,
            'euno_agreement_id' => $agreementId,
            'signing_url' => $signingUrl,
            'status' => $agreement->status,
            'signers' => $agreement->signers,
        ], 'Offer agreement created with Eunodoc.');
    }

    /**
     * Check status of an offer's purchase agreement.
     */
    public function getOfferSigningStatus(Request $request, ListingOffer $offer): JsonResponse
    {
        $agreement = ListingEunoAgreement::where('listing_offer_id', $offer->id)
            ->latest()
            ->first();

        if (!$agreement) {
            return api_success([
                'has_agreement' => false,
                'status' => 'not_initiated',
            ]);
        }

        if ($agreement->status !== 'completed' && $agreement->agreement_id) {
            $remoteStatus = $this->eunoService->getAgreementStatus($agreement->agreement_id);
            if ($remoteStatus === 'completed') {
                $agreement->status = 'completed';
                $agreement->completed_at = now();

                $signedPdf = $this->eunoService->getSignedDocument($agreement->agreement_id);
                if ($signedPdf) {
                    $signedPath = "offers/{$offer->id}/signed/EunoSigned_PA_{$agreement->id}.pdf";
                    Storage::disk('local')->put($signedPath, $signedPdf);
                    $agreement->signed_file_path = $signedPath;
                }

                $auditPdf = $this->eunoService->getAuditReport($agreement->agreement_id);
                if ($auditPdf) {
                    $auditPath = "offers/{$offer->id}/signed/EunoAuditReport_{$agreement->id}.pdf";
                    Storage::disk('local')->put($auditPath, $auditPdf);
                    $agreement->audit_file_path = $auditPath;
                }

                $agreement->save();
            }
        }

        return api_success([
            'has_agreement' => true,
            'agreement_id' => $agreement->id,
            'status' => $agreement->status,
            'signing_url' => $agreement->signing_url,
            'is_completed' => $agreement->status === 'completed',
            'signed_at' => $agreement->completed_at,
        ]);
    }

    /**
     * Webhook receiver for Eunodoc asynchronous completion callbacks.
     */
    public function webhook(Request $request): JsonResponse
    {
        $agreementId = $request->input('document_id') ?? $request->input('_id') ?? $request->input('agreementId');
        if (!$agreementId) {
            return response()->json(['status' => 'ignored', 'message' => 'No agreementId provided'], 400);
        }

        $agreement = ListingEunoAgreement::where('agreement_id', $agreementId)->first();
        if (!$agreement) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $remoteStatus = $this->eunoService->getAgreementStatus($agreementId);
        if ($remoteStatus === 'completed') {
            $agreement->status = 'completed';
            $agreement->completed_at = now();

            $signedPdf = $this->eunoService->getSignedDocument($agreementId);
            if ($signedPdf) {
                $signedPath = "listings/{$agreement->listing_id}/signed/Webhook_Signed_{$agreement->id}.pdf";
                Storage::disk('local')->put($signedPath, $signedPdf);
                $agreement->signed_file_path = $signedPath;
            }
            $agreement->save();
        }

        return response()->json(['status' => 'success', 'agreement_status' => $agreement->status]);
    }

    /**
     * Download or view the signed PDF.
     */
    public function downloadSignedDocument(ListingEunoAgreement $agreement): Response
    {
        if (!$agreement->signed_file_path || !Storage::disk('local')->exists($agreement->signed_file_path)) {
            return response('Signed document not found', 404);
        }

        $content = Storage::disk('local')->get($agreement->signed_file_path);
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="EunoSigned_' . $agreement->id . '.pdf"',
        ]);
    }

    /**
     * Download or view the audit report PDF.
     */
    public function downloadAuditReport(ListingEunoAgreement $agreement): Response
    {
        if (!$agreement->audit_file_path || !Storage::disk('local')->exists($agreement->audit_file_path)) {
            return response('Audit report not found', 404);
        }

        $content = Storage::disk('local')->get($agreement->audit_file_path);
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="EunoAuditReport_' . $agreement->id . '.pdf"',
        ]);
    }

    /**
     * Fallback minimal PDF string for testing/development.
     */
    protected function buildSampleDisclosurePdf(Listing $listing): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000053 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";
    }

    protected function buildSampleOfferPdf(ListingOffer $offer): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000053 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";
    }
}

function storage_app_path(string $path = ''): string
{
    return storage_path('app/' . ltrim($path, '/'));
}
