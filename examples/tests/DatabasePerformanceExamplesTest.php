<?php

use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Performance\OrderFeed;

uses()->group('database-performance');

/**
 * Executed claims for chapters/data/database-performance.md.
 */
it('verifies the access path uses the index instead of a scan', function () {
    /** @example-id perf/index-usage */
    // Seed enough rows that a scan would be obviously wrong, then read the
    // plan the engine actually takes for the indexed filter+order shape.
    foreach (range(1, 20) as $i) {
        DB::table('orders')->insert([
            'customer_id' => $i,
            'sku_id' => $i,
            'status' => 'open',
            'quantity' => 1,
            'placed_at' => now(),
        ]);
    }

    $plan = DB::select("EXPLAIN QUERY PLAN SELECT id FROM orders WHERE status = 'open' ORDER BY placed_at DESC");
    $detail = collect($plan)->pluck('detail')->implode(' ');

    // SEEK (index), not SCAN (full table walk) — the claim the migration's
    // index([status, placed_at]) is making. SQLite phrases the seek as
    // "SEARCH ... USING [COVERING ]INDEX ...".
    expect($detail)->toContain('SEARCH');
    expect($detail)->toContain('INDEX');
    expect($detail)->not->toContain('SCAN');
});

it('inserts a batch in one statement instead of one per row', function () {
    /** @example-id perf/bulk-insert */
    $rows = [];
    foreach (range(1, 5) as $i) {
        $rows[] = ['key' => "k{$i}", 'created_at' => now()];
    }

    DB::enableQueryLog();
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('race_log')->insert($chunk);
    }
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // One round trip for five rows — the bulk shape, not the per-row loop.
    expect(count($log))->toBe(1);
    expect(DB::table('race_log')->count())->toBe(5);
});

it('keeps keyset pagination stable where offset pagination shifts', function () {
    /** @example-id perf/keyset-pagination */
    $feed = new OrderFeed;

    // Explicit positive ids (SQLite AUTOINCREMENT turns an inserted 0 into
    // an auto-generated value — a lesson the first run taught us).
    foreach (range(100, 109) as $i) {
        DB::table('orders')->insert([
            'id' => $i,
            'customer_id' => 1,
            'sku_id' => $i,
            'status' => 'open',
            'quantity' => 1,
            'placed_at' => now(),
        ]);
    }

    // Reader walks pages keyset-style; a NEW row (id 50, "before" the whole
    // window) lands between page 1 and page 2 — the concurrent-insert case.
    [$page1, $cursor] = $feed->page(null, 3);
    expect(collect($page1)->pluck('id')->all())->toBe([100, 101, 102]);

    DB::table('orders')->insert([
        'id' => 50, 'customer_id' => 9, 'sku_id' => 99,
        'status' => 'open', 'quantity' => 1, 'placed_at' => now(),
    ]);

    [$page2] = $feed->page($cursor, 3);
    $keysetIds = collect($page2)->pluck('id')->all();

    // Keyset: strictly the rows AFTER the cursor — no duplicates, no skips.
    expect($keysetIds)->toBe([103, 104, 105]);

    // Offset pagination on the same state: the insert (id 50) shifted every
    // window, so "page 2" re-serves one row the reader already saw —
    // duplicated content, and rows never skip (offset's trade: slow AND
    // duplicating, vs keyset: fast AND correct here).
    $offsetPage2 = DB::table('orders')->orderBy('id')->skip(3)->take(3)->get(['id'])->pluck('id')->all();
    expect($offsetPage2)->toBe([102, 103, 104]);
    expect(collect($offsetPage2)->intersect([100, 101, 102])->count())->toBe(1);
    expect(collect($keysetIds)->intersect([100, 101, 102])->count())->toBe(0);
});
