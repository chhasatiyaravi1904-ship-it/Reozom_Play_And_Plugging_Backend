<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment lists for the admin (everything) and agents (their own package payments and the
 * seller payments for their service packages).
 */
class PaymentHistoryController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        return $this->respond(Payment::query(), $request);
    }

    public function agentIndex(Request $request): JsonResponse
    {
        $agentId = $request->user()->id;

        $query = Payment::query()->where(function (Builder $q) use ($agentId) {
            $q->where(fn ($own) => $own->where('type', Payment::TYPE_AGENT_PACKAGE)->where('user_id', $agentId))
              ->orWhere(fn ($earned) => $earned->where('type', Payment::TYPE_LISTING)->where('agent_id', $agentId));
        });

        return $this->respond($query, $request);
    }

    private function respond(Builder $query, Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => 'nullable|in:agent_package,listing',
            'status' => 'nullable|in:pending,paid,failed,expired,refunded',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($s) use ($search) {
                    $s->where('description', 'like', "%{$search}%")
                      ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
                });
            });

        // Totals over the filtered set (before pagination); amounts are in cents.
        $totals = (clone $query)->selectRaw('status, COUNT(*) as count, SUM(amount) as amount, SUM(platform_fee) as platform_fee, SUM(processing_fee) as processing_fee, SUM(agent_amount) as agent_amount')
            ->groupBy('status')->get()
            ->mapWithKeys(fn ($row) => [$row->status => [
                'count' => (int) $row->count,
                'amount' => ((int) $row->amount) / 100,
                'platform_fee' => ((int) $row->platform_fee) / 100,
                'processing_fee' => ((int) $row->processing_fee) / 100,
                'agent_amount' => ((int) $row->agent_amount) / 100,
            ]]);

        $page = $query->with([
            'user:id,name,email',
            'agent:id,name,email',
            'package:id,name',
            'listing:id,reference_code,address,city,state',
            'servicePackage:id,name',
        ])->latest()->paginate($filters['per_page'] ?? 20);

        return api_success([
            'items' => collect($page->items())->map(fn (Payment $p) => [
                'id' => $p->id,
                'type' => $p->type,
                'status' => $p->status,
                'amount' => $p->amountDecimal(),
                'currency' => $p->currency,
                'platform_fee' => $p->platform_fee / 100,
                'processing_fee' => $p->processing_fee / 100,
                'agent_amount' => $p->agent_amount / 100,
                'refund_requested' => (bool) $p->stripe_refund_id,
                'description' => $p->description,
                'payer' => $p->user?->only(['id', 'name', 'email']),
                'agent' => $p->agent?->only(['id', 'name', 'email']),
                'package' => $p->package?->only(['id', 'name']),
                'service_package' => $p->servicePackage?->only(['id', 'name']),
                'listing' => $p->listing?->only(['id', 'reference_code', 'address', 'city', 'state']),
                'stripe_payment_intent_id' => $p->stripe_payment_intent_id,
                'paid_at' => $p->paid_at?->toIso8601String(),
                'refunded_at' => $p->refunded_at?->toIso8601String(),
                'created_at' => $p->created_at?->toIso8601String(),
            ])->all(),
            'totals' => $totals,
            'currency' => config('services.stripe.currency', 'usd'),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
