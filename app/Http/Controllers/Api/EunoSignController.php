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
     * Send the listing's unsigned documents to EunoDoc as one "Reozom Documents" agreement,
     * with every signer (titled owner + additional signers), as Reozom does.
     */
    public function initiateListingSigning(Request $request, Listing $listing): JsonResponse
    {
        $user = $request->user();

        if ($user->id !== $listing->user_id && ! $user->isAdmin()) {
            return api_error('Unauthorized to initiate signing for this listing.', 403);
        }

        $signers = $listing->eSignSigners();
        if ($signers === []) {
            return api_error('No signer email found. Add the owner email in the listing before signing.', 422);
        }

        $forceRecreate = $request->boolean('force_recreate', false);
        if (! $forceRecreate) {
            $existing = ListingEunoAgreement::where('listing_id', $listing->id)
                ->whereNull('listing_offer_id')
                ->where('status', 'inprogress')
                ->latest()
                ->first();

            if ($existing && ! empty($existing->signing_url)) {
                return api_success($this->agreementPayload($existing), 'Existing in-progress EunoDoc signature agreement retrieved.');
            }
        }

        $docController = app(ListingDocumentController::class);
        $documents = $docController->signableDocuments($listing)
            ->filter(fn (ListingDocument $doc) => $doc->status !== 'signed')
            ->values();

        if ($documents->isEmpty()) {
            return api_error('All documents are already signed.', 422);
        }

        // Unsigned PDFs carrying the $sN signature tags. Kept apart from the document's own
        // files so a later view/download never serves this copy by mistake.
        $sourcePaths = [];
        foreach ($documents as $doc) {
            $relPath = "listings/{$listing->id}/unsigned/{$doc->type}.pdf";
            Storage::disk('local')->put($relPath, $docController->renderPdf($listing, $doc));
            $sourcePaths[$doc->id] = $relPath;
        }

        $filePaths = array_map(fn ($p) => Storage::disk('local')->path($p), array_values($sourcePaths));
        $eunoSigners = array_map(fn ($s) => $s + ['type' => 'signer'], $signers);

        $response = $this->eunoService->getAgreementKey($filePaths, 'Reozom Documents', $eunoSigners);
        if (! $response || empty($response['_id'])) {
            return api_error('Could not start e-signing with EunoDoc. Please try again later.', 502);
        }

        $recipients = $response['recipients'] ?? [];

        // Send the current user to their own signing link; otherwise the titled owner's.
        $signerEmails = array_column($signers, 'email');
        $linkEmail = in_array(strtolower($user->email), $signerEmails, true) ? $user->email : $signers[0]['email'];
        $signingUrl = $this->eunoService->getSigningUrlForUser($response['_id'], $linkEmail, $recipients);

        // Close any older open agreement for this listing so only one is active.
        ListingEunoAgreement::where('listing_id', $listing->id)
            ->whereNull('listing_offer_id')
            ->where('status', 'inprogress')
            ->update(['status' => 'canceled']);

        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id,
            'initiated_by_user_id' => $user->id,
            'document_type' => 'disclosures_bundle',
            'document_name' => 'Reozom Documents',
            'agreement_id' => $response['_id'],
            'signing_url' => $signingUrl,
            'status' => 'inprogress',
            'signers' => $this->mergeRecipientIds($signers, $recipients),
            // Keyed by listing_documents.id so completion marks exactly these documents signed.
            'source_file_paths' => $sourcePaths,
            'sent_at' => now(),
        ]);

        return api_success($this->agreementPayload($agreement), 'EunoDoc signature agreement created successfully.');
    }

    /**
     * Listing-document agreements still waiting to be signed where the current user is a
     * signer, each with that user's own signing link (shown as a pop-up after login).
     */
    public function pendingSignatures(Request $request): JsonResponse
    {
        $email = strtolower((string) $request->user()->email);
        $like = '%'.addcslashes($email, '\\%_').'%';

        $items = ListingEunoAgreement::query()
            ->whereNull('listing_offer_id')
            ->where('status', 'inprogress')
            ->whereNotNull('agreement_id')
            // Cheap pre-filter; the exact email match is checked below.
            ->where('signers', 'like', $like)
            ->with('listing:id,address,city,state,zip')
            ->latest()
            ->limit(20)
            ->get()
            ->map(function (ListingEunoAgreement $agreement) use ($email, $request) {
                $signer = collect($agreement->signers ?? [])
                    ->first(fn ($s) => strtolower((string) ($s['email'] ?? '')) === $email);
                if (! $signer || ! $agreement->listing) {
                    return null;
                }

                // Each signer gets their own EunoDoc link, built from their recipient id.
                if (! empty($signer['recipient_id'])) {
                    $url = $this->eunoService->getSigningUrlForUser(
                        $agreement->agreement_id,
                        $email,
                        [['email' => $email, '_id' => $signer['recipient_id']]]
                    );
                } elseif ($agreement->initiated_by_user_id === $request->user()->id) {
                    $url = $agreement->signing_url;
                } else {
                    $url = null;
                }
                if (! $url) {
                    return null;
                }

                $listing = $agreement->listing;

                return [
                    'agreement_id' => $agreement->id,
                    'listing_id' => $listing->id,
                    'listing_address' => trim(implode(', ', array_filter([$listing->address, $listing->city, trim("{$listing->state} {$listing->zip}")]))),
                    'document_name' => $agreement->document_name,
                    'document_count' => count($agreement->source_file_paths ?? []),
                    'sent_at' => $agreement->sent_at,
                    'signing_url' => $url,
                ];
            })
            ->filter()
            ->values();

        return api_success(['items' => $items, 'count' => $items->count()]);
    }

    /**
     * Status of the listing's latest EunoDoc agreement; stores the signed PDF once completed.
     */
    public function getListingSigningStatus(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $agreement = ListingEunoAgreement::where('listing_id', $listing->id)
            ->whereNull('listing_offer_id')
            ->latest()
            ->first();

        if (! $agreement) {
            return api_success([
                'has_agreement' => false,
                'status' => 'not_initiated',
                'signers' => $listing->eSignSigners(),
            ]);
        }

        if (! in_array($agreement->status, ['completed', 'canceled'], true) && $agreement->agreement_id) {
            $remoteStatus = $this->eunoService->getAgreementStatus($agreement->agreement_id);
            if ($remoteStatus === 'completed') {
                $this->completeListingAgreement($agreement);
            } elseif ($remoteStatus !== 'unknown') {
                $agreement->update(['status' => $remoteStatus]);
            }
        }

        return api_success([
            'has_agreement' => true,
            'is_completed' => $agreement->status === 'completed',
            'signed_at' => $agreement->completed_at,
            'has_signed_pdf' => (bool) $agreement->signed_file_path,
            'has_audit_pdf' => (bool) $agreement->audit_file_path,
        ] + $this->agreementPayload($agreement));
    }

    /**
     * Store EunoDoc's signed packet and audit report, and mark only the documents that were
     * part of this agreement as signed.
     */
    protected function completeListingAgreement(ListingEunoAgreement $agreement): void
    {
        if ($agreement->status === 'completed' && $agreement->signed_file_path) {
            return;
        }

        $signedPdf = $this->eunoService->getSignedDocument($agreement->agreement_id);
        if (! $signedPdf) {
            // Don't mark anything signed until the signed copy is actually stored.
            return;
        }

        $signedPath = "listings/{$agreement->listing_id}/signed/EunoSigned_Documents_{$agreement->id}.pdf";
        Storage::disk('local')->put($signedPath, $signedPdf);

        $auditPdf = $this->eunoService->getAuditReport($agreement->agreement_id);
        $auditPath = null;
        if ($auditPdf) {
            $auditPath = "listings/{$agreement->listing_id}/signed/EunoAuditReport_{$agreement->id}.pdf";
            Storage::disk('local')->put($auditPath, $auditPdf);
        }

        $agreement->update([
            'status' => 'completed',
            'completed_at' => now(),
            'signed_file_path' => $signedPath,
            'audit_file_path' => $auditPath,
        ]);

        $signers = $agreement->signers ?? [];
        $documentIds = array_keys($agreement->source_file_paths ?? []);

        ListingDocument::where('listing_id', $agreement->listing_id)
            ->whereIn('id', $documentIds)
            ->get()
            ->each(fn (ListingDocument $doc) => $doc->update([
                'status' => 'signed',
                'signed_at' => now(),
                'signed_by' => implode(', ', array_column($signers, 'name')),
                'signature_type' => 'eunodoc',
                'signature_image' => null,
                'initials_image' => null,
                'verification_code' => $agreement->agreement_id,
                'audit_trail' => [
                    'provider' => 'eunodoc',
                    'listing_euno_agreement_id' => $agreement->id,
                    'euno_agreement_id' => $agreement->agreement_id,
                    'signers' => $signers,
                    'completed_at' => now()->toIso8601String(),
                ],
                'admin_notes' => 'Electronically signed via EunoDoc.',
            ]));
    }

    /**
     * @param  array<int, array{name: string, email: string}>  $signers
     */
    protected function mergeRecipientIds(array $signers, array $recipients): array
    {
        return array_map(function (array $signer) use ($recipients) {
            foreach ($recipients as $recipient) {
                if (strcasecmp($recipient['email'] ?? '', $signer['email']) === 0) {
                    $signer['recipient_id'] = $recipient['_id'] ?? null;
                    break;
                }
            }

            return $signer;
        }, $signers);
    }

    protected function agreementPayload(ListingEunoAgreement $agreement): array
    {
        return [
            'agreement_id' => $agreement->id,
            'euno_agreement_id' => $agreement->agreement_id,
            'signing_url' => $agreement->signing_url,
            'status' => $agreement->status,
            'signers' => array_map(
                fn ($s) => ['name' => $s['name'] ?? '', 'email' => $s['email'] ?? ''],
                $agreement->signers ?? []
            ),
        ];
    }

    /**
     * Initiate EunoDoc e-sign flow for a Purchase Agreement on an offer.
     */
    public function initiateOfferSigning(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $listing = $offer->listing;

        // Ensure user is buyer, seller, or admin
        if ($user->id !== $offer->buyer_id && $user->id !== $listing->user_id && !$user->isAdmin()) {
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
        $signingUrl = $this->eunoService->getSigningUrlForUser($agreementId, $user->email, $agreementResponse['recipients'] ?? []);

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
        if ($remoteStatus === 'completed' && $agreement->listing_offer_id === null) {
            $this->completeListingAgreement($agreement);
        } elseif ($remoteStatus === 'completed') {
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
     * Signed documents are private: admins, the listing's owner, the listing's
     * agent, and (for an offer agreement) the offer's buyer.
     */
    private function authorizeAgreementDownload(Request $request, ListingEunoAgreement $agreement): void
    {
        $user = $request->user();
        $listing = $agreement->listing;
        $offer = $agreement->listingOffer;

        $allowed = $user->isAdmin()
            || ($listing && $listing->user_id === $user->id)
            || ($listing && $user->isAgent() && $listing->servicePackage?->agent_id === $user->id)
            || ($offer && $offer->buyer_id === $user->id);

        abort_unless($allowed, 403, 'You are not allowed to download this document.');
    }

    /**
     * Download or view the signed PDF.
     */
    public function downloadSignedDocument(Request $request, ListingEunoAgreement $agreement): Response
    {
        $this->authorizeAgreementDownload($request, $agreement);

        if ((!$agreement->signed_file_path || !Storage::disk('local')->exists($agreement->signed_file_path)) && $agreement->agreement_id) {
            $signedPdf = $this->eunoService->getSignedDocument($agreement->agreement_id);
            if ($signedPdf) {
                $signedPath = "listings/{$agreement->listing_id}/signed/EunoSigned_Disclosures_{$agreement->id}.pdf";
                Storage::disk('local')->put($signedPath, $signedPdf);
                $agreement->update(['signed_file_path' => $signedPath]);
            }
        }

        if (!$agreement->signed_file_path || !Storage::disk('local')->exists($agreement->signed_file_path)) {
            return response('Signed document not found or pending execution.', 404);
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
    public function downloadAuditReport(Request $request, ListingEunoAgreement $agreement): Response
    {
        $this->authorizeAgreementDownload($request, $agreement);

        if ((!$agreement->audit_file_path || !Storage::disk('local')->exists($agreement->audit_file_path)) && $agreement->agreement_id) {
            $auditPdf = $this->eunoService->getAuditReport($agreement->agreement_id);
            if ($auditPdf) {
                $auditPath = "listings/{$agreement->listing_id}/signed/EunoAuditReport_{$agreement->id}.pdf";
                Storage::disk('local')->put($auditPath, $auditPdf);
                $agreement->update(['audit_file_path' => $auditPath]);
            }
        }

        if (!$agreement->audit_file_path || !Storage::disk('local')->exists($agreement->audit_file_path)) {
            return response('Audit report not found or pending execution.', 404);
        }

        $content = Storage::disk('local')->get($agreement->audit_file_path);
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="EunoAuditReport_' . $agreement->id . '.pdf"',
        ]);
    }

    protected function buildSampleOfferPdf(ListingOffer $offer): string
    {
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $controller = app(OfferController::class);
            $refMethod = new \ReflectionMethod($controller, 'renderPurchaseAgreementHtml');
            $refMethod->setAccessible(true);
            $html = $refMethod->invoke($controller, $offer);
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
        }

        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000053 00000 n\n0000000102 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";
    }
}

function storage_app_path(string $path = ''): string
{
    return storage_path('app/' . ltrim($path, '/'));
}
