<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared query building for index/list endpoints: search, exact-match
 * filtering, whitelisted sorting, and a paginated response shaped to match
 * the project's api_success() envelope (Laravel's default paginated
 * resource meta/links are lost when a resource collection is nested inside
 * that envelope, so we rebuild the meta explicitly here).
 */
trait FiltersAndPaginates
{
    private function applySearch(Builder $query, Request $request, string $column = 'name'): Builder
    {
        if ($search = $request->string('search')->trim()->value()) {
            $query->where($column, 'like', "%{$search}%");
        }

        return $query;
    }

    private function applyActiveFilter(Builder $query, Request $request): Builder
    {
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query;
    }

    private function applyExactFilter(Builder $query, Request $request, string $param, ?string $column = null): Builder
    {
        if ($value = $request->string($param)->value()) {
            $query->where($column ?? $param, $value);
        }

        return $query;
    }

    /**
     * @param  array<int, string>  $sortable  Whitelisted column names — never trust a raw request value for `ORDER BY`.
     */
    private function applySort(Builder $query, Request $request, array $sortable, string $default = 'name'): Builder
    {
        $sort = in_array($request->string('sort')->value(), $sortable, true)
            ? $request->string('sort')->value()
            : $default;

        return $query->orderBy($sort, $request->string('direction')->value() === 'desc' ? 'desc' : 'asc');
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    private function paginatedResponse(Builder $query, Request $request, string $resourceClass): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $paginator = $query->paginate($perPage);

        return api_success([
            'items' => $resourceClass::collection($paginator),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }
}
