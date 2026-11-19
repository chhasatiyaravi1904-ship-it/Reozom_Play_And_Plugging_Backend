<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\ConnectService;
use App\Services\PayoutService;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Agent balance and withdrawals, and the admin's overview of agents' payouts.
 */
class PayoutController extends Controller
{
    public function __construct(
        private readonly PayoutService $payouts,
        private readonly ConnectService $connect,
        private readonly StripeGateway $stripe,
    ) {}

    /**
     * Agent: balance (live from Stripe), earnings and withdrawal history.
     */
    public function agentIndex(Request $request): JsonResponse
    {
        $agent = $request->user()->fresh();

        $balance = ['available' => 0, 'pending' => 0];
        $balanceError = null;
        if ($agent->stripe_account_id && $this->stripe->isConfigured()) {
            try {
                $balance = $this->payouts->balance($agent);
            } catch (Throwable $e) {
                Log::warning('Stripe balance failed', ['user_id' => $agent->id, 'error' => $e->getMessage()]);
                $balanceError = 'Could not load your balance from Stripe right now.';
            }
        }

        $earned = Payment::where('type', Payment::TYPE_LISTING)
            ->where('agent_id', $agent->id)
            ->where('status', Payment::STATUS_PAID)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as gross, COALESCE(SUM(platform_fee),0) as platform_fee, COALESCE(SUM(processing_fee),0) as processing_fee, COALESCE(SUM(agent_amount),0) as net')
            ->first();

        return api_success([
            'account' => $this->connect->summary($agent),
            'currency' => config('services.stripe.currency', 'usd'),
            'balance' => ['available' => $balance['available'] / 100, 'pending' => $balance['pending'] / 100],
            'balance_error' => $balanceError,
            'min_withdrawal' => PayoutService::MIN_WITHDRAWAL / 100,
            'earnings' => [
                'sales' => (int) $earned->count,
                'gross' => $earned->gross / 100,
                'platform_fee' => $earned->platform_fee / 100,
                'processing_fee' => $earned->processing_fee / 100,
                'net' => $earned->net / 100,
            ],
            'payouts' => Payout::where('agent_id', $agent->id)->latest()->limit(50)->get()->map(fn (Payout $p) => $this->row($p))->all(),
        ]);
    }

    /**
     * Agent: withdraw from their Stripe balance to their bank.
     */
    public function withdraw(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        if (! $this->stripe->isConfigured()) {
            return api_error('Payouts are not available yet. Please contact support.', 503);
        }

        try {
            $payout = $this->payouts->withdraw($request->user()->fresh(), (int) round(((float) $data['amount']) * 100));
        } catch (ApiErrorException $e) {
            Log::error('Stripe payout failed', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return api_error('Stripe could not start this withdrawal: '.$e->getMessage(), 502);
        }

        return api_success($this->row($payout), 'Withdrawal started. It usually reaches your bank in 1-2 business days.', 201);
    }

    /**
     * Admin: every agent withdrawal.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => 'nullable|in:pending,in_transit,paid,failed,canceled',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Payout::with('agent:id,name,email')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest();

        $page = $query->paginate($filters['per_page'] ?? 20);

        return api_success([
            'items' => collect($page->items())->map(fn (Payout $p) => $this->row($p) + ['agent' => $p->agent?->only(['id', 'name', 'email'])])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Admin: every agent's payout account status and earnings.
     */
    public function adminAccounts(): JsonResponse
    {
        $agents = User::query()
            ->where('role', 'agent')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'stripe_account_id', 'stripe_details_submitted', 'stripe_charges_enabled',
                'stripe_payouts_enabled', 'stripe_disabled_reason', 'stripe_requirements_due', 'stripe_account_updated_at']);

        $earnings = Payment::where('type', Payment::TYPE_LISTING)->where('status', Payment::STATUS_PAID)
            ->groupBy('agent_id')
            ->selectRaw('agent_id, COALESCE(SUM(amount),0) as gross, COALESCE(SUM(agent_amount),0) as net, COALESCE(SUM(platform_fee),0) as platform_fee')
            ->get()->keyBy('agent_id');

        $withdrawn = Payout::where('status', Payout::STATUS_PAID)
            ->groupBy('agent_id')->selectRaw('agent_id, COALESCE(SUM(amount),0) as total')
            ->pluck('total', 'agent_id');

        return api_success($agents->map(fn (User $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'payout_status' => $a->payoutStatus(),
            'requirements_due' => (int) $a->stripe_requirements_due,
            'gross' => ($earnings[$a->id]->gross ?? 0) / 100,
            'net' => ($earnings[$a->id]->net ?? 0) / 100,
            'platform_fee' => ($earnings[$a->id]->platform_fee ?? 0) / 100,
            'withdrawn' => ($withdrawn[$a->id] ?? 0) / 100,
        ])->values()->all());
    }

    private function row(Payout $p): array
    {
        return [
            'id' => $p->id,
            'amount' => $p->amount / 100,
            'currency' => $p->currency,
            'status' => $p->status,
            'failure_message' => $p->failure_message,
            'arrival_date' => $p->arrival_date?->toDateString(),
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
