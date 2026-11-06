<?php

namespace MLE\Examples\Tests\Fixtures\Performance;

use Illuminate\Support\Facades\DB;

/**
 * perf/keyset-pagination — keyset (cursor) pagination: constant cost per
 * page, stable windows under concurrent inserts.
 */
final class OrderFeed
{
    /**
     * @return array{0: array<int, object>, 1: int|null} rows + next cursor
     */
    public function page(?int $afterId, int $limit = 20): array
    {
        $query = DB::table('orders')
            ->orderBy('id')
            ->limit($limit);

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query->get(['id', 'status'])->all();

        return [$rows, count($rows) === $limit ? ($rows[count($rows) - 1]->id ?? null) : null];
    }
}
