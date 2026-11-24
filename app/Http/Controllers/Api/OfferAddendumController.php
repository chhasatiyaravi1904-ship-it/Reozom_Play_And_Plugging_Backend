<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\OfferAddendum;
use App\Models\User;
use App\Services\EunoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Addendum/Amendment to an accepted purchase agreement, as in the original Reozom
 * (AddendumController::postAddendumAccept): a party writes the change, an Addendum PDF is
 * generated and sent through EunoDoc to the purchase agreement signers.
 */
class OfferAddendumController extends Controller
{
    public function __construct(protected EunoService $eunoService) {}

    public function index(Request $request, ListingOffer $offer): JsonResponse
    {
        if (! $this->canView($request->user(), $offer)) {
            return api_error('Unauthorized to view addendums on this offer.', 403);
        }

        $addendums = OfferAddendum::where('listing_offer_id', $offer->id)->with('agreement')->latest('id')->get()
            ->map(fn (OfferAddendum $addendum) => $this->payload($this->syncStatus($addendum)));

        return api_success($addendums);
    }

    public function store(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        // Buyer or listing owner (or admin) can amend an accepted purchase agreement
        if ($user->id !== $offer->buyer_id && $user->id !== $offer->listing?->user_id && ! $user->isAdmin()) {
            return api_error('Only the buyer or the listing owner can add an addendum.', 403);
        }
        if ($offer->status !== ListingOffer::STATUS_ACCEPTED) {
            return api_error('An addendum can only be added to an accepted offer.', 422);
        }

        $validated = $request->validate(['description' => ['required', 'string', 'max:2000']]);

        $signers = $offer->eSignSigners();
        if ($signers === []) {
            return api_error('No signer email found for this purchase agreement.', 422);
        }

        $addendum = OfferAddendum::create([
            'listing_offer_id' => $offer->id,
            'listing_id' => $offer->listing_id,
            'created_by_user_id' => $user->id,
            'description' => $validated['description'],
        ]);

        $path = "offers/{$offer->id}/addendums/Addendum_{$addendum->id}.pdf";
        Storage::disk('local')->put($path, $this->renderPdf($offer, $addendum, $signers));
        $filePath = Storage::disk('local')->path($path);

        $name = "Addendum #{$addendum->id} - Offer #{$offer->id}";
        $response = $this->eunoService->getAgreementKey([$filePath], $name, array_map(fn ($s) => $s + ['type' => 'signer'], $signers));
        if (! $response || empty($response['_id'])) {
            $addendum->delete();
            Storage::disk('local')->delete($path);

            return api_error('Could not send the addendum to EunoDoc. Please try again later.', 502);
        }

        $recipients = $response['recipients'] ?? [];
        $agreement = ListingEunoAgreement::create([
            'listing_id' => $offer->listing_id,
            'listing_offer_id' => $offer->id,
            'initiated_by_user_id' => $user->id,
            'document_type' => 'addendum',
            'document_name' => $name,
            'agreement_id' => $response['_id'],
            'status' => 'inprogress',
            'signers' => $this->withRecipientIds($signers, $recipients),
            'source_file_paths' => [$filePath],
            'sent_at' => now(),
        ]);
        // The creator's own link only (never another signer's)
        $agreement->update(['signing_url' => $this->signingUrlFor($agreement, $offer, $user)]);
        $addendum->update(['listing_euno_agreement_id' => $agreement->id]);

        return api_success($this->payload($addendum->load('agreement'), $user), 'Addendum sent for signature.', 201);
    }

    /**
     * The current user's own EunoDoc signing link for the addendum.
     */
    public function sign(Request $request, ListingOffer $offer, OfferAddendum $addendum): JsonResponse
    {
        $user = $request->user();
        if ($addendum->listing_offer_id !== $offer->id || ! $this->canView($user, $offer)) {
            return api_error('Unauthorized to sign this addendum.', 403);
        }

        $agreement = $addendum->agreement;
        if (! $agreement || $agreement->status !== 'inprogress') {
            return api_error('This addendum is not waiting for signatures.', 422);
        }

        return api_success($this->payload($addendum, $user));
    }

    /**
     * Cancel an addendum (original Reozom offerCancledAddendum): cancel its EunoDoc agreement,
     * remove the copies shared with the seller and buyer, and delete it. Buyer, listing owner or admin.
     */
    public function destroy(Request $request, ListingOffer $offer, OfferAddendum $addendum): JsonResponse
    {
        $user = $request->user();
        if ($addendum->listing_offer_id !== $offer->id) {
            abort(404);
        }
        if ($user->id !== $offer->buyer_id && $user->id !== $offer->listing?->user_id && ! $user->isAdmin()) {
            return api_error('Only the buyer or the listing owner can cancel an addendum.', 403);
        }

        $agreement = $addendum->agreement;
        if ($agreement) {
            if ($agreement->status === 'inprogress' && $agreement->agreement_id
                && $this->eunoService->cancelAgreement($agreement->agreement_id) === null) {
                \Illuminate\Support\Facades\Log::warning('EunoDoc cancel failed; addendum canceled locally.', ['offer_addendum_id' => $addendum->id]);
            }
            $agreement->update(['status' => 'canceled']);
        }

        $addendum->removeSharedCopies();
        Storage::disk('local')->delete("offers/{$offer->id}/addendums/Addendum_{$addendum->id}.pdf");
        $addendum->delete();

        return api_success(null, 'Addendum canceled.');
    }

    /**
     * The user's own EunoDoc link to the addendum: their signer entry by email, or the seller
     * signer for the listing owner's account. Null for anyone who doesn't sign.
     */
    private function signingUrlFor(ListingEunoAgreement $agreement, ?ListingOffer $offer, User $user): ?string
    {
        $signer = $offer?->signerFor($user, $agreement->signers ?? []);
        $recipientId = $signer['recipient_id'] ?? $signer['_id'] ?? null;
        if (! $signer || empty($recipientId) || ! $agreement->agreement_id) {
            return null;
        }

        return $this->eunoService->getSigningUrlForUser(
            $agreement->agreement_id,
            (string) $signer['email'],
            [['email' => (string) $signer['email'], '_id' => $recipientId]]
        );
    }

    private function canView(User $user, ListingOffer $offer): bool
    {
        $listing = $offer->listing;

        return $user->isAdmin()
            || $user->id === $offer->buyer_id
            || $user->id === $listing?->user_id
            || ($user->isAgent() && $listing?->servicePackage?->agent_id === $user->id)
            || in_array(strtolower((string) $user->email), array_column($offer->eSignSigners(), 'email'), true);
    }

    /** Pick up completion from EunoDoc and keep the signed copy. */
    private function syncStatus(OfferAddendum $addendum): OfferAddendum
    {
        $agreement = $addendum->agreement;
        if ($agreement) {
            // Same completion as the status pages, webhook and scheduled euno:sync
            app(EunoSignController::class)->syncAgreement($agreement);
        }

        return $addendum;
    }

    private function payload(OfferAddendum $addendum, ?User $forUser = null): array
    {
        $agreement = $addendum->agreement;
        $user = $forUser ?? request()->user();

        $signingUrl = $agreement?->status === 'inprogress' && $user
            ? $this->signingUrlFor($agreement, $addendum->offer, $user)
            : null;

        return [
            'id' => $addendum->id,
            'description' => $addendum->description,
            'created_at' => $addendum->created_at?->toIso8601String(),
            'status' => $agreement?->status ?? 'not_sent',
            'agreement_id' => $agreement?->id,
            'has_signed_pdf' => (bool) $agreement?->signed_file_path,
            'signing_url' => $signingUrl,
            'signers' => array_map(fn ($s) => ['name' => $s['name'] ?? '', 'email' => $s['email'] ?? ''], $agreement?->signers ?? []),
        ];
    }

    /**
     * @param  array<int, array{name: string, email: string, role: string}>  $signers
     */
    private function withRecipientIds(array $signers, array $recipients): array
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

    /**
     * Reozom pdf.offers.miRdAddendum: property, purchase agreement date, the change, the
     * "addendum prevails" clause and one signature line per purchase agreement signer ($sN).
     */
    private function renderPdf(ListingOffer $offer, OfferAddendum $addendum, array $signers): string
    {
        $listing = $offer->listing;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $address = $e(trim("{$listing->address}, {$listing->city}, {$listing->state} {$listing->zip}", ' ,'));
        $paDate = $e(($offer->created_at ?? now())->format('F j, Y'));
        $today = $e(now()->format('F j, Y'));
        $description = nl2br($e($addendum->description));
        $signatures = app(OfferController::class)->purchaseAgreementSignatureLines($signers);

        $html = <<<HTML
<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
    h1 { text-align: center; font-size: 18px; margin-bottom: 18px; }
    table.info { width: 80%; border-collapse: collapse; margin-bottom: 18px; }
    table.info td { border: 1pt solid #000; padding: 4px 6px; }
    .handwrite { font-family: Courier, monospace; font-weight: bold; }
</style></head>
<body>
    <h1>ADDENDUM/AMENDMENT TO PURCHASE AGREEMENT</h1>
    <table class="info">
        <tr><td style="width:40%;">Regarding property commonly known as:</td><td class="handwrite">{$address}</td></tr>
        <tr><td>Purchase Agreement dated</td><td class="handwrite">{$paDate}</td></tr>
        <tr><td>Offer reference</td><td class="handwrite">{$e($offer->reference_code)}</td></tr>
        <tr><td>Addendum date</td><td class="handwrite">{$today}</td></tr>
    </table>
    <p>By and between the undersigned parties.</p>
    <p>In reference to the above mentioned sale and Purchase Agreement by and between the undersigned parties,
       it is hereby agreed that the following is being added and/or amended to read as follows:</p>
    <p class="handwrite" style="text-decoration:underline;line-height:1.5;">{$description}</p>
    <p>If there is conflict between terms and provisions of Purchase Agreement and this addendum,
       the agreed upon terms of this Addendum shall prevail.</p>
    {$signatures}
</body></html>
HTML;

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
    }
}
