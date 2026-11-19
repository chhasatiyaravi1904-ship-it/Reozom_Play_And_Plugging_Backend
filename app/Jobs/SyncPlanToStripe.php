<?php

namespace App\Jobs;

use App\Services\StripeCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Push one plan (admin package or agent service package) to Stripe.
 * Errors are recorded on the plan by the catalog service, so the job itself doesn't fail.
 */
class SyncPlanToStripe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $modelClass,
        public int|string $planId,
    ) {}

    public function handle(StripeCatalogService $catalog): void
    {
        $plan = $this->modelClass::find($this->planId);
        if ($plan) {
            $catalog->sync($plan);
        }
    }
}
