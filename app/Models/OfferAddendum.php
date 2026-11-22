<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Addendum/Amendment to an accepted purchase agreement (original Reozom Addendum).
 */
class OfferAddendum extends Model
{
    protected $table = 'offer_addendums';

    protected $fillable = [
        'listing_offer_id',
        'listing_id',
        'created_by_user_id',
        'description',
        'listing_euno_agreement_id',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ListingOffer::class, 'listing_offer_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ListingEunoAgreement::class, 'listing_euno_agreement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Undo shareSignedCopy(): remove the seller's listing copy and the buyer's library copy
     * (Reozom offerCancledAddendum deletes both).
     */
    public function removeSharedCopies(): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $offer = $this->offer;
        if (! $offer) {
            return;
        }

        $fileName = "Addendum_{$this->id}.pdf";

        ListingDocument::where('listing_id', $offer->listing_id)
            ->where('path', "listings/{$offer->listing_id}/documents/{$fileName}")
            ->get()
            ->each(function (ListingDocument $doc) use ($disk) {
                $disk->delete($doc->path);
                $doc->delete();
            });

        if ($offer->buyer_id) {
            UserDocument::where('user_id', $offer->buyer_id)
                ->where('path', "users/{$offer->buyer_id}/documents/{$fileName}")
                ->get()
                ->each(function (UserDocument $doc) use ($disk) {
                    $disk->delete($doc->path);
                    $doc->delete();
                });
        }
    }

    /**
     * Once signed, the addendum is shared like in Reozom (AddendumController::storeDataAsPerRole):
     * a copy in the listing's Uploaded Documents (seller) and in the buyer's own library.
     * Safe to call more than once.
     */
    public function shareSignedCopy(string $signedPath): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $offer = $this->offer()->with(['listing', 'buyer'])->first();
        if (! $offer?->listing || ! $disk->exists($signedPath)) {
            return;
        }

        $name = "Addendum #{$this->id} to Purchase Agreement";
        $fileName = "Addendum_{$this->id}.pdf";
        $size = $disk->size($signedPath);

        $listingPath = "listings/{$offer->listing_id}/documents/{$fileName}";
        if (! ListingDocument::where('listing_id', $offer->listing_id)->where('path', $listingPath)->exists()) {
            $disk->copy($signedPath, $listingPath);
            $offer->listing->documents()->create([
                'user_id' => $offer->listing->user_id,
                'type' => 'addendum',
                'original_name' => "{$name}.pdf",
                'path' => $listingPath,
                'mime_type' => 'application/pdf',
                'size' => $size,
                'status' => 'uploaded',
                'admin_notes' => "Signed addendum for offer {$offer->reference_code}.",
            ]);
        }

        if ($offer->buyer) {
            $buyerPath = "users/{$offer->buyer->id}/documents/{$fileName}";
            if (! UserDocument::where('user_id', $offer->buyer->id)->where('path', $buyerPath)->exists()) {
                $disk->copy($signedPath, $buyerPath);
                UserDocument::create([
                    'user_id' => $offer->buyer->id,
                    'listing_id' => $offer->listing_id,
                    'name' => $name,
                    'original_name' => "{$name}.pdf",
                    'path' => $buyerPath,
                    'mime_type' => 'application/pdf',
                    'size' => $size,
                    'source' => UserDocument::SOURCE_ADDENDUM,
                ]);
            }
        }
    }
}
