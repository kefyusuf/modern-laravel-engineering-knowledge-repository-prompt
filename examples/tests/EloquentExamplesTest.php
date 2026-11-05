<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Models\Inventory;

/**
 * Executed claims for chapters/data/eloquent.md.
 */
it('turns lazy loading into a loud failure when the guard is on', function () {
    /** @example-id eloquent/lazy-loading-guard */
    Inventory::create(['id' => 1, 'available' => 5]);
    DB::table('orders')->insert([
        ['customer_id' => 1, 'sku_id' => 1, 'quantity' => 1, 'status' => 'open', 'placed_at' => now()],
    ]);

    Model::preventLazyLoading(true);

    $inventory = Inventory::query()->first();

    // Unguarded relation access: the N+1 becomes an exception, not a query.
    expect(fn () => $inventory->orders)->toThrow(LazyLoadingViolationException::class);

    // Eager-loaded access is untouched.
    $eager = Inventory::query()->with('orders')->first();
    expect($eager->orders)->toHaveCount(1);
});

it('pages by id so concurrent mutation cannot skip rows', function () {
    /** @example-id eloquent/chunk-by-id */
    foreach (range(1, 10) as $i) {
        DB::table('orders')->insert([
            'customer_id' => 1, 'sku_id' => 1, 'quantity' => 1,
            'status' => 'stale', 'placed_at' => now(),
        ]);
    }

    // chunkById: visited rows are deleted mid-iteration (the concurrent
    // mutation), but the id cursor keeps the iteration complete.
    $visitedById = [];
    DB::table('orders')->chunkById(3, function ($chunk) use (&$visitedById) {
        $visitedById = array_merge($visitedById, $chunk->pluck('id')->all());
        DB::table('orders')->whereIn('id', $chunk->pluck('id'))->delete();
    });
    expect(count($visitedById))->toBe(10);

    // Reset, then the offset-based chunk: the same mutation shifts offsets
    // and silently skips unvisited rows — the production incident.
    foreach (range(1, 10) as $i) {
        DB::table('orders')->insert([
            'customer_id' => 1, 'sku_id' => 1, 'quantity' => 1,
            'status' => 'stale', 'placed_at' => now(),
        ]);
    }
    $visitedByOffset = [];
    DB::table('orders')->orderBy('id')->chunk(3, function ($chunk) use (&$visitedByOffset) {
        $visitedByOffset = array_merge($visitedByOffset, $chunk->pluck('id')->all());
        DB::table('orders')->whereIn('id', $chunk->pluck('id'))->delete();
    });
    expect(count($visitedByOffset))->toBeLessThan(10);
});

it('skips model hydration with toBase on row-shaped reads', function () {
    /** @example-id eloquent/base-objects */
    Inventory::create(['id' => 1, 'available' => 5]);

    $eloquentRows = Inventory::query()->select('id', 'available')->get();
    expect($eloquentRows[0])->toBeInstanceOf(Inventory::class);

    $baseRows = Inventory::query()->select('id', 'available')->toBase()->get();
    expect($baseRows[0])->toBeInstanceOf(stdClass::class);
    expect($baseRows[0])->not->toBeInstanceOf(Inventory::class);
    expect($baseRows[0]->available)->toBe(5);
});

afterEach(function () {
    Model::preventLazyLoading(false);
    Model::shouldBeStrict(false);
});
