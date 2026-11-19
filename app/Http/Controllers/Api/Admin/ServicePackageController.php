<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only admin list of the service packages agents created.
 */
class ServicePackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServicePackage::query()
            ->with(['agent:id,name,email', 'listingProcess:id,name'])
            ->withCount(['zipCodes', 'listings']);

        if ($search = trim((string) $request->input('search'))) {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhereHas('agent', fn ($aq) => $aq->where('name', 'like', $like)->orWhere('email', 'like', $like));
            });
        }

        $status = $request->input('status');
        if ($status === 'active' || $status === 'inactive') {
            $query->where('is_active', $status === 'active');
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $packages = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($packages->items())->map(fn (ServicePackage $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'price' => $p->price,
                'is_active' => (bool) $p->is_active,
                'agent' => $p->agent ? ['id' => $p->agent->id, 'name' => $p->agent->name, 'email' => $p->agent->email] : null,
                'listing_process' => $p->listingProcess?->name,
                'zip_codes_count' => $p->zip_codes_count,
                'listings_count' => $p->listings_count,
                'created_at' => $p->created_at,
            ]),
            'meta' => [
                'current_page' => $packages->currentPage(),
                'last_page' => $packages->lastPage(),
                'per_page' => $packages->perPage(),
                'total' => $packages->total(),
            ],
        ]);
    }
}
