<?php

namespace App\Jobs;

use App\Services\StripeGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A deleted plan's Stripe Product and Price are archived (Stripe keeps them for past payments).
 */
class ArchiveStripeProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $productId,
        public ?string $priceId = null,
    ) {}

    public function handle(StripeGateway $stripe): void
    {
        if (! $stripe->isConfigured()) {
            return;
        }

        // Stripe refuses to archive a product's default price, so clear it first.
        $stripe->updateProduct($this->productId, ['active' => false, 'default_price' => '']);
        if ($this->priceId) {
            $stripe->archivePrice($this->priceId);
        }
    }
}
