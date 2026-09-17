<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    /** Tek tip liste yanıtı: { data: [], meta: { page, per_page, total, last_page } } */
    protected function paginated(LengthAwarePaginator $paginator, ?callable $map = null, array $extraMeta = []): JsonResponse
    {
        $items = collect($paginator->items());

        return response()->json([
            'data' => $map ? $items->map($map)->values() : $items->values(),
            'meta' => array_merge([
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ], $extraMeta),
        ]);
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(5, min(200, (int) $request->integer('per_page', $default)));
    }

    /**
     * ?sort=-created_at gibi sıralama; yalnızca izin verilen kolonlar (SQL enjeksiyonu yok).
     *
     * @param array<string, string> $allowed  istemci anahtarı => SQL kolonu
     */
    protected function applySort(Builder $query, Request $request, array $allowed, string $default): void
    {
        $sort = (string) $request->query('sort', $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (! isset($allowed[$key])) {
            $key = ltrim($default, '-');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        $query->orderBy($allowed[$key], $direction);
    }

    protected function ok(string $message, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $message] + $extra);
    }

    /** Tarih aralığı filtresi: ?from=2026-09-01&to=2026-09-30 */
    protected function applyDateRange(Builder $query, Request $request, string $column): void
    {
        if ($from = $request->date('from')) {
            $query->where($column, '>=', $from->startOfDay());
        }
        if ($to = $request->date('to')) {
            $query->where($column, '<=', $to->endOfDay());
        }
    }
}
