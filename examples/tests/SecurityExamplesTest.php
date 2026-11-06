<?php

use Illuminate\Database\Eloquent\Model;
use MLE\Examples\Tests\Fixtures\Models\User;

uses()->group('security');

/**
 * Executed claims for chapters/interfaces/security.md.
 */
it('keeps injected SQL as data when values are bound', function () {
    /** @example-id security/sql-injection-parameterization */
    foreach (range(1, 5) as $i) {
        DB::table('orders')->insert([
            'customer_id' => $i,
            'sku_id' => $i,
            'status' => 'open',
            'quantity' => 1,
            'placed_at' => now(),
        ]);
    }

    // The VULNERABLE construction: input concatenated into SQL. The
    // injection payload is benign by design ("1 OR 1=1") — it only
    // demonstrates that the input EXECUTED as SQL, not as data.
    $malicious = "10 OR 1=1";
    $vulnerableRows = DB::select("SELECT id FROM orders WHERE customer_id = {$malicious}");
    expect(count($vulnerableRows))->toBe(5); // the input changed the query's MEANING

    // The FIXED construction: the same payload bound as a value stays DATA —
    // it matches nothing (no customer id is that string).
    $boundRows = DB::select(
        'SELECT id FROM orders WHERE customer_id = ?',
        [$malicious],
    );
    expect(count($boundRows))->toBe(0);
});

it('blocks mass assignment by default and can be made loud about it', function () {
    /** @example-id security/mass-assignment-guard */
    // Default posture: non-fillable attributes are silently discarded —
    // the client's `password_hash` (or `is_admin`) attempt simply lands
    // nowhere.
    $profile = new User([
        'tenant_id' => 1,
        'is_admin' => false,
        'role' => 'superadmin', // NOT in $fillable — the injected privilege
    ]);

    expect($profile->role)->toBeNull(); // discarded, silently

    // The stricter posture: the same attempt becomes a loud failure —
    // the typo/breach class dies in CI (the chapter's recommended setting
    // outside production).
    Model::preventSilentlyDiscardingAttributes(true);

    expect(fn () => new User([
        'tenant_id' => 1,
        'role' => 'superadmin',
    ]))->toThrow(\Illuminate\Database\Eloquent\MassAssignmentException::class);
});

afterEach(function () {
    Model::preventSilentlyDiscardingAttributes(false);
});
