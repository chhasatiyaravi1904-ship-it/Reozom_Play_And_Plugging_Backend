<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\ServicePackage;
use App\Services\StripeCatalogService;
use App\Services\StripeGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Retry" for a plan whose Stripe sync failed or hasn't run yet: syncs it right now.
 */
class PlanStripeSyncController extends Controller
{
    public function __construct(
        private readonly StripeCatalogService $catalog,
        private readonly StripeGateway $stripe,
    ) {}

    public function package(Package $package): JsonResponse
    {
        return $this->sync($package);
    }

    public function servicePackage(Request $request, ServicePackage $servicePackage): JsonResponse
    {
        if (! $request->user()->isAdmin() && $servicePackage->agent_id !== $request->user()->id) {
            return api_error('Unauthorized', 403);
        }

        return $this->sync($servicePackage);
    }

    private function sync(Model $plan): JsonResponse
    {
        if (! $this->stripe->isConfigured()) {
            return api_error('Stripe is not configured yet (STRIPE_SECRET).', 503);
        }

        $plan = $this->catalog->sync($plan)->fresh();
        $summary = $plan->stripeSyncSummary();

        return $summary['status'] === 'failed'
            ? api_error('Stripe sync failed: '.$summary['error'], 502, ['stripe_sync' => $summary])
            : api_success($summary, 'Plan synced with Stripe.');
    }
}
