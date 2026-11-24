<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Push every admin plan and agent plan to Stripe (first setup, or after Stripe was unreachable).
Artisan::command('stripe:sync-plans', function (\App\Services\StripeCatalogService $catalog, \App\Services\StripeGateway $stripe) {
    if (! $stripe->isConfigured()) {
        $this->error('Stripe is not configured. Set STRIPE_SECRET in .env first.');

        return 1;
    }

    $failed = 0;
    foreach ([\App\Models\Package::class, \App\Models\ServicePackage::class] as $model) {
        foreach ($model::query()->orderBy('id')->cursor() as $plan) {
            $status = $catalog->sync($plan)->fresh()->stripeSyncStatus();
            $failed += $status === 'failed' ? 1 : 0;
            $this->line(sprintf('%-16s %-40s %s', $plan->stripePlanType(), mb_strimwidth($plan->name, 0, 38, '…'), $status));
        }
    }

    $failed ? $this->warn("{$failed} plan(s) failed; see the error on the plans page and use Retry.") : $this->info('All plans are in Stripe.');

    return $failed ? 1 : 0;
})->purpose('Create or update all plans in Stripe');

// Pick up e-signing progress from EunoDoc without anyone opening a page: completes finished
// listing-document, purchase-agreement and addendum agreements and stores the signed copies.
Artisan::command('euno:sync', function () {
    $controller = app(\App\Http\Controllers\Api\EunoSignController::class);
    $counts = ['completed' => 0, 'open' => 0, 'failed' => 0];

    \App\Models\ListingEunoAgreement::query()
        ->where('status', 'inprogress')
        ->whereNotNull('agreement_id')
        ->orderBy('id')
        ->each(function (\App\Models\ListingEunoAgreement $agreement) use ($controller, &$counts) {
            try {
                $status = $controller->syncAgreement($agreement);
                $counts[$status === 'completed' ? 'completed' : 'open']++;
            } catch (\Throwable $e) {
                // One failing agreement must not stop the rest; the next run retries it
                $counts['failed']++;
                \Illuminate\Support\Facades\Log::warning('euno:sync failed for agreement', [
                    'listing_euno_agreement_id' => $agreement->id,
                    'message' => $e->getMessage(),
                ]);
            }
        });

    $this->info("EunoDoc sync: {$counts['completed']} completed, {$counts['open']} not completed yet, {$counts['failed']} failed.");

    return $counts['failed'] ? 1 : 0;
})->purpose('Sync open e-signing agreements from EunoDoc');

\Illuminate\Support\Facades\Schedule::command('euno:sync')->everyFiveMinutes()->withoutOverlapping();
