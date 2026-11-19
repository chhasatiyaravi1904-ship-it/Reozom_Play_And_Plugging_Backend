<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a plan's Stripe Product + Price in line with the database.
 *
 * - Paid & active: Product exists and is active; a Price exists for the current amount.
 *   A changed amount creates a new Price and archives the old one (Stripe Prices can't change).
 * - Free or inactive: Product (if any) is archived, so it can't be sold.
 * Errors are stored on the plan (shown with a Retry button) instead of failing the save.
 */
class StripeCatalogService
{
    public function __construct(private readonly StripeGateway $stripe) {}

    /**
     * @param  Model&\App\Models\Concerns\SyncsToStripe  $plan
     */
    public function sync(Model $plan): Model
    {
        if (! $this->stripe->isConfigured()) {
            return $plan;
        }

        try {
            $plan->isSellableOnStripe() ? $this->publish($plan) : $this->archive($plan);

            $plan->forceFill(['stripe_synced_at' => now(), 'stripe_sync_error' => null])->saveQuietly();
        } catch (Throwable $e) {
            Log::warning('Stripe plan sync failed', [
                'plan' => $plan->stripePlanType(), 'id' => $plan->getKey(), 'error' => $e->getMessage(),
            ]);
            $plan->forceFill(['stripe_sync_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
        }

        return $plan;
    }

    private function publish(Model $plan): void
    {
        $productData = [
            'name' => $plan->name,
            'description' => filled($plan->description) ? mb_substr(strip_tags($plan->description), 0, 500) : null,
            'active' => true,
            'metadata' => $plan->stripeMetadata(),
        ];

        if ($plan->stripe_product_id) {
            $this->stripe->updateProduct($plan->stripe_product_id, $productData);
        } else {
            $params = array_filter($productData, fn ($v) => $v !== null);
            // Same key for an identical retry (no duplicate product); Stripe rejects a reused key
            // with different parameters, so the key includes a hash of them.
            $productId = $this->stripe->createProduct(
                $params,
                "product-{$plan->stripePlanType()}-{$plan->getKey()}-".md5(json_encode($params))
            );
            $plan->forceFill(['stripe_product_id' => $productId])->saveQuietly();
        }

        if ($plan->hasCurrentStripePrice()) {
            return;
        }

        $amount = $plan->stripeAmount();
        $oldPriceId = $plan->stripe_price_id;

        $priceId = $this->stripe->createPrice([
            'product' => $plan->stripe_product_id,
            'unit_amount' => $amount,
            'currency' => config('services.stripe.currency', 'usd'),
            'metadata' => $plan->stripeMetadata(),
        ], "price-{$plan->stripePlanType()}-{$plan->getKey()}-{$amount}");

        $this->stripe->updateProduct($plan->stripe_product_id, ['default_price' => $priceId]);
        $plan->forceFill(['stripe_price_id' => $priceId, 'stripe_price_amount' => $amount])->saveQuietly();

        if ($oldPriceId && $oldPriceId !== $priceId) {
            $this->stripe->archivePrice($oldPriceId);
        }
    }

    private function archive(Model $plan): void
    {
        if ($plan->stripe_product_id) {
            $this->stripe->updateProduct($plan->stripe_product_id, ['active' => false]);
        }
    }
}
