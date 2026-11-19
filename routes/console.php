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
