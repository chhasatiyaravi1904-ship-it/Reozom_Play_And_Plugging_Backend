<?php

namespace App\Models\Concerns;

use App\Jobs\ArchiveStripeProduct;
use App\Jobs\SyncPlanToStripe;
use App\Services\PaymentService;

/**
 * A plan (admin package or agent service package) mirrored in Stripe as a Product + Price.
 * Saving a change that matters to Stripe queues a sync after the database commit.
 */
trait SyncsToStripe
{
    /** Fields whose change must be pushed to Stripe. */
    protected static array $stripeSyncedFields = ['name', 'description', 'price', 'is_active'];

    public static function bootSyncsToStripe(): void
    {
        static::saved(function (self $plan) {
            if ($plan->wasRecentlyCreated || $plan->wasChanged(static::$stripeSyncedFields)) {
                SyncPlanToStripe::dispatch(static::class, $plan->getKey())->afterCommit();
            }
        });

        static::deleted(function (self $plan) {
            if ($plan->stripe_product_id) {
                ArchiveStripeProduct::dispatch($plan->stripe_product_id, $plan->stripe_price_id)->afterCommit();
            }
        });
    }

    public function initializeSyncsToStripe(): void
    {
        $this->mergeCasts(['stripe_synced_at' => 'datetime', 'stripe_price_amount' => 'integer']);
    }

    /** Price in cents, as charged. */
    public function stripeAmount(): int
    {
        return PaymentService::toCents($this->price);
    }

    /** Free or inactive plans are not sold through Stripe. */
    public function isSellableOnStripe(): bool
    {
        return (bool) $this->is_active && $this->stripeAmount() > 0;
    }

    /** True when Stripe has a Price for exactly the current amount. */
    public function hasCurrentStripePrice(): bool
    {
        return $this->stripe_price_id !== null && (int) $this->stripe_price_amount === $this->stripeAmount();
    }

    /**
     * synced | pending | failed | not_required
     */
    public function stripeSyncStatus(): string
    {
        if (! $this->isSellableOnStripe()) {
            return 'not_required';
        }
        if ($this->stripe_sync_error) {
            return 'failed';
        }

        return $this->hasCurrentStripePrice() ? 'synced' : 'pending';
    }

    public function stripeSyncSummary(): array
    {
        return [
            'status' => $this->stripeSyncStatus(),
            'error' => $this->stripe_sync_error,
            'synced_at' => $this->stripe_synced_at?->toIso8601String(),
            'product_id' => $this->stripe_product_id,
        ];
    }

    /** Short type used in Stripe metadata and job logs. */
    abstract public function stripePlanType(): string;

    /** Extra Stripe metadata (IDs only, never personal data). */
    public function stripeMetadata(): array
    {
        return ['plan_type' => $this->stripePlanType(), 'plan_id' => (string) $this->getKey()];
    }
}
