<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use MLE\Examples\Tests\Fixtures\Models\Inventory;

uses()->group('eloquent');

/**
 * Executed claims for chapters/data/eloquent.md.
 */
it('turns lazy loading into a loud failure when the guard is on', function () {
    /** @example-id eloquent/lazy-loading-guard */
    // NOTE (Laravel 12 behavior, verified in source): Builder::hydrate()
    // copies the static guard onto hydrated instances only when the result
    // set has MORE THAN ONE row — a single first() model does NOT carry the
    // flag. The realistic N+1 (a listing) is exactly the multi-row case.
    foreach (range(1, 2) as $i) {
        Inventory::create(['id' => $i, 'available' => 5]);
    }
    DB::table('orders')->insert([
        ['customer_id' => 1, 'sku_id' => 1, 'quantity' => 1, 'status' => 'open', 'placed_at' => now()],
    ]);

    Model::preventLazyLoading(true);

    $inventories = Inventory::query()->get();

    // Unguarded relation access: the N+1 becomes an exception, not a query.
    // The violation class moved namespaces in Laravel 12 (verified in the
    // framework source): Illuminate\Database\Eloquent\ on 10–11,
    // Illuminate\Database\ on 12+.
    $violationClass = class_exists(\Illuminate\Database\LazyLoadingViolationException::class)
        ? \Illuminate\Database\LazyLoadingViolationException::class
        : \Illuminate\Database\Eloquent\LazyLoadingViolationException::class;

    expect(fn () => $inventories[0]->orders)->toThrow($violationClass);

    // Eager-loaded access is untouched.
    $eager = Inventory::query()->with('orders')->get();
    expect($eager[0]->orders)->toHaveCount(1);
});

it('pages by id so concurrent mutation cannot skip rows', function () {
    /** @example-id eloquent/chunk-by-id */
    foreach (range(1, 10) as $i) {
        DB::table('orders')->insert([
            'customer_id' => 1, 'sku_id' => $i, 'quantity' => 1,
            'status' => 'stale', 'placed_at' => now(),
        ]);
    }

    // chunkById: visited rows are deleted mid-iteration (the concurrent
    // mutation), but the id cursor keeps the iteration complete.
    // sku_id varies per row: the harness unique index forbids duplicates.
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
            'customer_id' => 1, 'sku_id' => $i, 'quantity' => 1,
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
