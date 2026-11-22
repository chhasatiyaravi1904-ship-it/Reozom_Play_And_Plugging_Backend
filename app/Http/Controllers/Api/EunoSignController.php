<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
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

        if ($listing->isAgentListing()) {
            return api_error('Agent listings have no listing documents to sign.', 422);
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
        $documentPages = [];
        foreach ($documents as $doc) {
            $relPath = "listings/{$listing->id}/unsigned/{$doc->type}.pdf";
            $pdf = $docController->renderPdf($listing, $doc);
            Storage::disk('local')->put($relPath, $pdf);
            $sourcePaths[$doc->id] = $relPath;
            // Page ranges let the signed packet be split per document afterwards (Reozom documentSplit)
            $documentPages[$doc->id] = $this->pdfPageCount($pdf);
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
            'document_pages' => in_array(null, $documentPages, true) ? null : $documentPages,
            'sent_at' => now(),
        ]);

        return api_success($this->agreementPayload($agreement), 'EunoDoc signature agreement created successfully.');
    }

    /**
     * Agreements still waiting for the current user's signature, each with that user's own signing
     * link (shown as a pop-up after login): listing documents (in signing order: only the next
     * signer), and, like Reozom's dashboard, purchase agreements and addendums the user has not
     * signed yet (all parties at once).
     */
    public function pendingSignatures(Request $request): JsonResponse
    {
        $email = strtolower((string) $request->user()->email);
        $like = '%'.addcslashes($email, '\\%_').'%';

        $items = ListingEunoAgreement::query()
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

                $progress = $this->signingProgress($agreement);
                if ($agreement->listing_offer_id === null) {
                    // Listing documents: owner first, then each additional signer; only the next one gets a link
                    if ($progress['next_email'] !== $email) {
                        return null;
                    }
                } elseif (collect($progress['signers'])->firstWhere('email', $email)['signed'] ?? false) {
                    // Purchase agreement / addendum: everyone signs at once; skip if this user already signed
                    return null;
                }

                // Each signer gets their own EunoDoc link, built from their recipient id
                // (older offer agreements stored EunoDoc's raw recipients with _id).
                $recipientId = $signer['recipient_id'] ?? $signer['_id'] ?? null;
                if (! empty($recipientId)) {
                    $url = $this->eunoService->getSigningUrlForUser(
                        $agreement->agreement_id,
                        $email,
                        [['email' => $email, '_id' => $recipientId]]
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
                    // listing_documents | purchase_agreement | addendum
                    'kind' => $agreement->listing_offer_id === null ? 'listing_documents' : ($agreement->document_type ?: 'purchase_agreement'),
                    'offer_id' => $agreement->listing_offer_id,
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
                'is_agent_listing' => $listing->isAgentListing(),
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

        $payload = [
            'has_agreement' => true,
            'is_agent_listing' => $listing->isAgentListing(),
            'is_completed' => $agreement->status === 'completed',
            'signed_at' => $agreement->completed_at,
            'has_signed_pdf' => (bool) $agreement->signed_file_path,
            'has_audit_pdf' => (bool) $agreement->audit_file_path,
        ] + $this->agreementPayload($agreement);

        if ($agreement->status === 'inprogress') {
            $progress = $this->signingProgress($agreement);
            $payload['signers'] = $progress['signers'];
            $payload['next_signer'] = $progress['next_name'];
            // Only the next signer may sign now; everyone else waits for them
            $payload['can_sign_now'] = $progress['next_email'] === strtolower((string) $request->user()->email);
        }

        return api_success($payload);
    }

    /**
     * Who has signed and who signs next. Signers sign in the stored order (titled owner, then
     * the additional signers); the next one is the first whose EunoDoc recipient status is not done.
     *
     * @return array{signers: array<int, array{name: string, email: string, signed: bool, is_next: bool}>, next_email: ?string, next_name: ?string}
     */
    protected function signingProgress(ListingEunoAgreement $agreement): array
    {
        $statuses = [];
        $details = $agreement->agreement_id ? $this->eunoService->getDocumentUrl($agreement->agreement_id) : null;
        foreach ((array) ($details['recipients'] ?? []) as $recipient) {
            $statuses[strtolower((string) ($recipient['email'] ?? ''))] = strtolower((string) ($recipient['status'] ?? ''));
        }

        $nextEmail = null;
        $nextName = null;
        $signers = [];
        foreach ($agreement->signers ?? [] as $signer) {
            $email = strtolower((string) ($signer['email'] ?? ''));
            $signed = in_array($statuses[$email] ?? '', ['signed', 'completed'], true);
            $isNext = ! $signed && $nextEmail === null;
            if ($isNext) {
                $nextEmail = $email;
                $nextName = (string) ($signer['name'] ?? $email);
            }
            $signers[] = ['name' => (string) ($signer['name'] ?? ''), 'email' => $email, 'signed' => $signed, 'is_next' => $isNext];
        }

        return ['signers' => $signers, 'next_email' => $nextEmail, 'next_name' => $nextName];
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
        $splitPaths = $this->splitSignedPacket($agreement, $signedPath);

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
                    // This document's own signed pages, when the packet could be split
                    'signed_file_path' => $splitPaths[$doc->id] ?? null,
                ],
                'admin_notes' => 'Electronically signed via EunoDoc.',
            ]));
    }

    private function pdfPageCount(string $pdf): ?int
    {
        try {
            return count((new \Smalot\PdfParser\Parser())->parseContent($pdf)->getPages()) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Split the signed packet into one signed PDF per document, like Reozom's documentSplit + qpdf.
     * Packet pages follow the order the documents were sent in; EunoDoc may add pages (audit) at the
     * end, which are ignored. Without qpdf, page counts or enough pages nothing is split and each
     * document keeps serving the whole signed packet.
     *
     * @return array<int|string, string> listing_document_id => signed file path (local disk)
     */
    protected function splitSignedPacket(ListingEunoAgreement $agreement, string $signedPath): array
    {
        $pages = $agreement->document_pages ?? [];
        if ($pages === []) {
            return [];
        }

        $signedPages = $this->pdfPageCount(Storage::disk('local')->get($signedPath));
        if (! $signedPages || $signedPages < array_sum($pages)) {
            \Illuminate\Support\Facades\Log::warning('Signed packet has fewer pages than sent; not split.', ['listing_euno_agreement_id' => $agreement->id]);

            return [];
        }

        $input = Storage::disk('local')->path($signedPath);
        $paths = [];
        $start = 1;
        foreach ($pages as $documentId => $count) {
            $end = $start + (int) $count - 1;
            $relPath = "listings/{$agreement->listing_id}/signed/Agreement_{$agreement->id}_Document_{$documentId}.pdf";
            Storage::disk('local')->makeDirectory(dirname($relPath));

            $result = \Illuminate\Support\Facades\Process::run([
                config('euno.qpdf_path', 'qpdf'), '--empty', '--pages', $input, "{$start}-{$end}", '--', Storage::disk('local')->path($relPath),
            ]);
            if ($result->successful() && Storage::disk('local')->exists($relPath)) {
                $paths[$documentId] = $relPath;
            } else {
                \Illuminate\Support\Facades\Log::warning('qpdf could not split the signed packet.', [
                    'listing_euno_agreement_id' => $agreement->id, 'error' => $result->errorOutput(),
                ]);
            }
            $start = $end + 1;
        }

        return $paths;
    }

    /**
     * @param  array<int, array{email: string}>  $signers
     */
    protected function isSigner(array $signers, ?string $email): bool
    {
        return in_array(strtolower((string) $email), array_column($signers, 'email'), true);
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

        // Buyer, listing owner, admin, or anyone who signs the purchase agreement
        $signers = $offer->eSignSigners();
        if ($user->id !== $offer->buyer_id && $user->id !== $listing->user_id && ! $user->isAdmin()
            && ! $this->isSigner($signers, $user->email)) {
            return api_error('Unauthorized to initiate signing on this offer.', 403);
        }

        // The purchase agreement is only signed once the seller has accepted the offer.
        if ($offer->status !== ListingOffer::STATUS_ACCEPTED) {
            return api_error('The purchase agreement can be signed once the offer is accepted.', 422);
        }

        // Buyer and seller must sign the SAME agreement: if one is already open, hand this
        // user their own link to it instead of creating a second agreement.
        $existing = ListingEunoAgreement::where('listing_offer_id', $offer->id)
            ->where('document_type', 'purchase_agreement')
            ->where('status', 'inprogress')
            ->whereNotNull('agreement_id')
            ->latest()
            ->first();
        if ($existing) {
            // Stored signers carry recipient_id (older agreements stored EunoDoc's raw recipients with _id)
            $recipients = array_map(
                fn ($s) => ['email' => $s['email'] ?? '', '_id' => $s['recipient_id'] ?? $s['_id'] ?? null],
                $existing->signers ?? []
            );
            $signingUrl = $this->eunoService->getSigningUrlForUser($existing->agreement_id, $user->email, $recipients)
                ?? $existing->signing_url;

            return api_success([
                'agreement_id' => $existing->id,
                'euno_agreement_id' => $existing->agreement_id,
                'signing_url' => $signingUrl,
                'status' => $existing->status,
                'signers' => $existing->signers,
            ], 'Existing offer agreement retrieved.');
        }

        return $this->createOfferAgreement($offer, $user, $signers, 'Offer agreement created with Eunodoc.');
    }

    /**
     * Cancel the open purchase agreement and send a new one with the current terms and signers
     * (original Reozom: admin regenerate). Listing owner or admin only.
     */
    public function resendOfferSigning(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        if ($user->id !== $offer->listing?->user_id && ! $user->isAdmin()) {
            return api_error('Only the listing owner or an admin can resend the purchase agreement.', 403);
        }
        if ($offer->status !== ListingOffer::STATUS_ACCEPTED) {
            return api_error('The purchase agreement can be signed once the offer is accepted.', 422);
        }

        app(\App\Services\OfferService::class)->cancelOpenPurchaseAgreements($offer);

        return $this->createOfferAgreement($offer, $user, $offer->eSignSigners(), 'Purchase agreement sent again for signature.');
    }

    /**
     * One EunoDoc agreement for the offer, as in Reozom: the generated Purchase Agreement plus the
     * listing's Lead and Seller's Disclosure, unless the property is new construction, the listing
     * is agent-owned or the owner is a non-owner-occupied trust. Files a party uploaded are
     * attachments only and are never sent for signature.
     *
     * @param  array<int, array{name: string, email: string, role: string}>  $signers
     */
    protected function createOfferAgreement(ListingOffer $offer, $user, array $signers, string $message): JsonResponse
    {
        $listing = $offer->listing;
        if ($signers === []) {
            return api_error('No signer email found for this purchase agreement.', 422);
        }

        // Unsigned copies built for this agreement, kept apart from any uploaded offer files
        $dir = "offers/{$offer->id}/unsigned";
        $relPaths = ["{$dir}/Purchase_Agreement.pdf"];
        Storage::disk('local')->put($relPaths[0], $this->buildSampleOfferPdf($offer));

        if ($this->offerPacketIncludesDisclosures($listing)) {
            $docController = app(ListingDocumentController::class);
            foreach ($docController->offerDisclosureDocuments($listing) as $doc) {
                $path = "{$dir}/{$doc->type}.pdf";
                Storage::disk('local')->put($path, $docController->renderPdf($listing, $doc, $signers));
                $relPaths[] = $path;
            }
        }
        $filePaths = array_map(fn ($p) => Storage::disk('local')->path($p), $relPaths);

        $docName = "Purchase Agreement - Offer #{$offer->id}";
        $eunoSigners = array_map(fn ($s) => $s + ['type' => 'signer'], $signers);
        $agreementResponse = $this->eunoService->getAgreementKey($filePaths, $docName, $eunoSigners);

        if (! $agreementResponse || empty($agreementResponse['_id'])) {
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
            // Names and roles in signing order, each with their EunoDoc recipient id
            'signers' => $this->mergeRecipientIds($signers, $agreementResponse['recipients'] ?? []),
            'source_file_paths' => $filePaths,
            'sent_at' => now(),
        ]);

        return api_success([
            'agreement_id' => $agreement->id,
            'euno_agreement_id' => $agreementId,
            'signing_url' => $signingUrl,
            'status' => $agreement->status,
            'signers' => $agreement->signers,
        ], $message);
    }

    /**
     * Reozom leaves the Lead and Seller's Disclosure out of the purchase agreement for new
     * construction, agent-owned listings and non-owner-occupied trusts.
     */
    protected function offerPacketIncludesDisclosures(Listing $listing): bool
    {
        if ($listing->isAgentListing()) {
            return false;
        }

        $values = $listing->answerValues();
        if (in_array(strtolower((string) ($values['new_construction'] ?? '')), ['yes', '1', 'true'], true)) {
            return false;
        }

        return ! \App\Models\ListingProcess::conditionMatches(\App\Models\ListingProcess::TRUST_NON_OWNER_SKIP_RULE, $values);
    }

    /**
     * Check status of an offer's purchase agreement.
     */
    public function getOfferSigningStatus(Request $request, ListingOffer $offer): JsonResponse
    {
        // The response carries the signing link, so only the offer's parties may read it:
        // buyer, seller, the agent on the listing's plan, or an admin.
        $user = $request->user();
        $listing = $offer->listing;
        $allowed = $user->isAdmin()
            || $user->id === $offer->buyer_id
            || $user->id === $listing?->user_id
            || ($user->isAgent() && $listing?->servicePackage?->agent_id === $user->id)
            || $this->isSigner($offer->eSignSigners(), $user->email);
        if (! $allowed) {
            return api_error('Unauthorized to view signing on this offer.', 403);
        }

        $agreement = ListingEunoAgreement::where('listing_offer_id', $offer->id)
            ->where('document_type', 'purchase_agreement')
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

            // A signed addendum is shared with the seller's listing and the buyer's library (Reozom)
            if ($agreement->document_type === 'addendum' && $agreement->signed_file_path) {
                \App\Models\OfferAddendum::where('listing_euno_agreement_id', $agreement->id)->first()
                    ?->shareSignedCopy($agreement->signed_file_path);
            }
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
