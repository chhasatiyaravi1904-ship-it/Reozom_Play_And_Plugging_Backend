<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Http\Resources\UserResource;
use App\Models\Package;
use App\Services\PackageActivationService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function __construct(
        private readonly PackageActivationService $activation,
    ) {}

    /**
     * The logged-in agent's subscription purchase history, newest first.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $user->currentAgentPackage()->value('id');

        $history = $user->agentPackages()
            ->with(['package:id,name,price,duration_days', 'payment'])
            ->latest('started_at')
            ->paginate(min(max((int) $request->input('per_page', 10), 1), 50));

        return response()->json([
            'data' => collect($history->items())->map(function (\App\Models\AgentPackage $row) use ($currentId) {
                $payment = $row->payment;
                $status = match (true) {
                    $payment?->status === \App\Models\Payment::STATUS_REFUNDED => 'refunded',
                    $row->id === $currentId => 'active',
                    $row->expires_at > now() => 'replaced',
                    default => 'expired',
                };

                return [
                    'id' => $row->id,
                    'package' => $row->package ? [
                        'id' => $row->package->id,
                        'name' => $row->package->name,
                        'duration_days' => $row->package->duration_days,
                    ] : null,
                    'started_at' => $row->started_at,
                    'expires_at' => $row->expires_at,
                    'status' => $status,
                    // Amounts are in cents, like the Payments page.
                    'amount' => $payment?->amount ?? 0,
                    'currency' => $payment?->currency ?? config('services.stripe.currency', 'usd'),
                    'paid_at' => $payment?->paid_at ?? null,
                    'payment_status' => $payment?->status,
                ];
            })->values(),
            'meta' => [
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
            ],
        ]);
    }

    /**
     * List the packages an agent can choose from.
     */
    public function index(): JsonResponse
    {
        $packages = Package::active()->orderBy('sort_order')->get();

        return api_success(PackageResource::collection($packages));
    }

    /**
     * Select (or renew/switch to) a free package. Paid packages must go through Stripe
     * Checkout (POST /packages/{package}/checkout) and are activated once Stripe confirms payment.
     */
    public function select(Request $request, Package $package): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAgent()) {
            return api_error('Only agents can select a package.', 403);
        }

        if (! $package->is_active) {
            return api_error('This package is not available.', 422);
        }

        if (! PaymentService::isFree($package->price)) {
            return api_error('This package requires payment.', 402);
        }

        $previous = $user->agentPackages()->latest('started_at')->first();
        if ($previous && $previous->package_id === $package->id && $previous->expires_at > now()) {
            return api_error('You already have this package active.', 422);
        }

        $this->activation->activate($user, $package, $request);

        $user->load('currentAgentPackage.package');

        return api_success(new UserResource($user), 'Package selected.');
    }
}
