---
title: Testing the failure paths
topic: testing
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/TestingExamplesTest.php
---

# Testing the failure paths

Every chapter in this handbook ends with a *How to test it* section because the
failure paths — races, retries, expiry, duplicates — are where production bugs
live. This chapter is the toolkit those sections assume: how to make concurrency,
failure, and time deterministic inside a test, and which fakes to distrust.

## TL;DR

- **Use it when:** the behavior under test is a *property* (at-most-once, no lost
  updates, expires after N) rather than a return value — the happy path already
  has tests.
- **Don't do it:** by sleeping (`usleep(500)`) or by hoping CI timing matches
  yours. Determinism is the whole discipline.
- **The classic production incident:** a suite that's green because it never
  exercises the failure branch — retries, rollbacks, and expiry paths are
  `// TODO` in tests, so the first real outage is the first execution.

## Problem

Three properties are hard to test naively:

1. **Concurrency** — two actors interleaving between "check" and "act". Real
   parallelism in tests is flaky; the fix is to *sequence the interleave by hand*.
2. **Failure** — what happens when a dependency throws mid-flow. Requires
   injecting the failure at a seam and letting the REAL machinery (worker, retry,
   failed-job handling) respond.
3. **Time** — TTLs, deadlines, backoff. Waiting real seconds is slow and
   non-deterministic; freeze the clock instead.

Laravel's testing surface is strong here — but each tool has a trap: fakes can
hide integration behavior, and transaction-wrapped tests can hide commit-time
behavior. The rest of this chapter is the trap map.

## When to use it

- Any pattern in this handbook before it ships: the race gets an interleave
  test, the retry policy gets a failure-injection test, the TTL gets a frozen-
  clock test.
- Regression tests for production incidents: reproduce the interleave that
  caused the incident, exactly and deterministically, before fixing it.

## When NOT to use it

- **As a substitute for integration/production verification.** SQLite is not
  MySQL; `:memory:` is not concurrent. The harness encodes *logic* claims; the
  database-engine claims (SKIP LOCKED, row-lock blocking) still need their real
  environment ([Transactions](../data/transactions.md), [outbox](../messaging/outbox.md)).
- **Mocking the thing under test.** If you fake the queue to test the queue,
  you've tested your fake. Fakes verify *your code's interaction* with the
  boundary; the boundary's behavior needs the real thing at least once (see
  "fakes vs machinery" below).
- **Snapshot-style race tests** that assert on timing ("finishes within 50ms")
  — they turn CI green/red randomly. Assert state, not duration.

## The simplest correct implementation

The interleave test — the single most reusable pattern in this chapter. Two
actors, hand-sequenced at the exact point where the bug lives:

<!-- example: testing/race-interleave -->
```php
<?php

use Illuminate\Support\Facades\DB;

/**
 * Demonstrates the interleave technique on a deliberately broken
 * implementation: naive check-then-insert with no unique index.
 * Each test boots a fresh :memory: database — no cross-test state.
 */
it('catches the check-then-insert race deterministically', function () {
    $check = fn (string $key) => DB::table('race_log')->where('key', $key)->exists();
    $insert = fn (string $key) => DB::table('race_log')->insert([
        'key' => $key, 'created_at' => now(),
    ]);

    // Actor A checks... actor B checks... A inserts... B inserts.
    $aSeesNothing = $check('k1');   // true
    $bSeesNothing = $check('k1');   // true — B reads before A writes
    $aSeesNothing && $insert('k1');
    $bSeesNothing && $insert('k1');

    // Two rows for one logical operation: the race is REPRODUCED, not
    // flaky — this test now fails only when the code is fixed.
    expect(DB::table('race_log')->where('key', 'k1')->count())->toBe(2);
});
```

That test is the RED step. The GREEN step is the constraint — a unique index plus
`insertOrIgnore` — and the same interleave then produces one row (the harness
runs both sides: `examples/tests/IdempotencyExamplesTest.php` holds the fixed
version). The technique generalizes to any check-then-act: lost updates,
oversell, dedupe — sequence the two actors around the vulnerable window and
assert the outcome.

## What production requires

### Fakes vs machinery — know which side you're testing

| Tool | Verifies | Trap |
|---|---|---|
| `Queue::fake()` / `Bus::fake()` | Your code dispatches the right job with the right payload | Nothing about retry, `afterCommit`, or worker behavior — the job never runs |
| Real database queue + `queue:work --once` | The full dispatch → enqueue → worker → handler path, in-process | Slower; needs the `jobs` table in the test schema |
| `Event::fake()` | Listeners are wired via events | Suppresses listener side effects — don't use it when the listener IS the feature |
| `DB::transaction()` for real | Commit/rollback semantics | `DatabaseTransactions` trait wraps everything in a never-committed transaction — it hides commit-time behavior (`afterCommit`, post-commit reads). Prefer `RefreshDatabase` |

The harness's standing rule ([examples](../../docs/examples.md)): dispatch-timing
claims are tested against the REAL database queue by counting `jobs` rows —
`Queue::fake()` cannot observe commit ordering.

### Failure injection at the seam

Inject the failure where the dependency contract lives, then let the real
machinery react — one worker cycle, and the whole retry/failed-job path executes:

<!-- example: testing/failure-injection -->
```php
<?php

use App\Jobs\SettlePayment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('lands exhausted jobs in failed_jobs and fires the failure hook', function () {
    config(['queue.default' => 'database']);

    SettlePayment::dispatch(1);

    // One real worker cycle: the job throws (its dependency fails in the
    // test), the worker records the failure — no mocking of the queue.
    Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(1);
    expect(SettlePayment::$failureNotifications)->toBe(1); // failed() ran
});
```

Rules that keep injection honest:

- **Inject at the type-hinted dependency** (a test binding or a throwing stub) —
  not by wrapping the job in try/catch, which tests nothing.
- **`$tries` shapes the test:** a job with `$tries = 10` will *release* for retry
  instead of failing on the first throw — the assertion must match the policy
  ([Queues](../messaging/queues.md) retry semantics).
- **Assert the side effects of `failed()`** — that hook is the alerting seam;
  it's also where silent failures hide.

### Frozen time

<!-- example: testing/time-freeze -->
```php
<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

it('expires cache entries at their TTL', function () {
    Cache::put('session:token', 'abc', now()->addSeconds(60));

    Carbon::setTestNow(now()->addSeconds(61));

    expect(Cache::get('session:token'))->toBeNull();
});
```

`Carbon::setTestNow()` freezes/shifts the application clock — TTLs, `retryUntil`,
`uniqueFor`, rate-limit windows all become instant. Two boundaries: the array
cache store's expiry honors the frozen clock, but **a real Redis server expires
keys on its own clock** — redis-backed TTL claims can't be time-traveled, only
bounded (or asserted via the server's TTL command). And always reset:
`Carbon::setTestNow()` in `afterEach`, or one test's frozen future poisons the
next.

## What usually goes wrong

1. **The suite that never fails.** *Root cause:* only happy paths tested; the
   failure branches exist only in production. *Detection:* incident postmortems
   that read "no test covered the retry path". *Fix:* the triad above — interleave,
   injection, freeze — applied per chapter's *How to test it*.
2. **Flaky concurrency tests.** *Root cause:* real threads/sleeps instead of
   sequenced interleaves. *Detection:* CI reruns that flip red/green. *Fix:*
   hand-sequenced actors; assert state, never duration.
3. **Fakes asserted as behavior.** *Root cause:* `Queue::assertPushed` treated as
   "the job ran". *Detection:* bugs inside the job that tests never saw. *Fix:*
   run the handler directly (`(new Job(...))->handle($dep)`) for logic, and keep
   one real-worker test for the machinery ([queues](../messaging/queues.md)).
4. **`DatabaseTransactions` hiding commit behavior.** *Root cause:* trait wraps
   every test in a transaction that never commits — `afterCommit` callbacks fire
   immediately (no surrounding commit boundary... or not at all, depending on
   version), post-commit reads can't be observed. *Detection:* afterCommit tests
   that pass with the callback mis-wired. *Fix:* `RefreshDatabase` for anything
   commit-shaped.
5. **Frozen clock leakage.** *Root cause:* `setTestNow` without reset. *Detection:*
   test-order-dependent failures ("passes alone, fails in suite"). *Fix:*
   `afterEach(fn () => Carbon::setTestNow())`.
6. **Tests that mirror the implementation.** *Root cause:* asserting internals
   (`$job->backoff()` equals a literal) instead of behavior. *Detection:* refactors
   that break tests without changing behavior. *Fix:* assert observable outcomes —
   rows, effects, exceptions — and keep the literal-introspection tests few and
   honest about what they pin ([Idempotency](../messaging/idempotency.md) tests
   show the balance).

## How to test it

This chapter is the test infrastructure — its own claims are demonstrated by the
harness files it references: the interleave (`TestingExamplesTest.php`), the
failure injection against a real worker cycle, and the frozen-clock expiry. The
meta-rule applies to the harness itself: every ⚙️ test in this repository asserts
state (rows, effects, exceptions), never timing.

## How to operate it

- **CI is the arbiter:** the suite runs on the version matrix
  ([strategy](../../docs/examples.md)); locally-green-but-CI-red is a version
  gate working as designed, not noise to skip.
- **Coverage is a map, not a score:** the failure-path checklist per chapter
  matters more than the percentage — 80% coverage with zero interleave tests
  still ships the race.
- **Incident → test pipeline:** every incident produces one deterministic test
  in the same PR as the fix; the interleave technique is how the race becomes
  code ([observability](observability.md) supplies the request/job ids that make
  postmortems testable).
- **Flake policy:** a flaky test is a broken test — quarantine with a ticket and
  a deadline, never a retry-until-green loop.

## How it scales

- **Suite runtime scales with real-machinery tests** — keep the pyramid honest:
  logic tests call handlers directly; one real-worker test per machinery claim;
  the matrix multiplies the rest ([queues](../messaging/queues.md)).
- **Deterministic concurrency** scales to any number of actors (sequence more
  interleave steps) without runtime cost — the technique doesn't degrade with
  scale the way sleep-based tests do.
- **The next level is property-based testing** for invariants that hold over
  generated inputs (dedupe over arbitrary key sets, retry over arbitrary failure
  sequences) — a deliberate addition, not a default.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| `RefreshDatabase` / `DatabaseTransactions` | ✅ | ✅ | ✅ | ✅ |
| `Queue::fake()` / `Bus::fake()` | ✅ | ✅ | ✅ | ✅ |
| `Carbon::setTestNow()` | ✅ | ✅ | ✅ | ✅ |
| `schedule:run --isolated` / `Isolatable` | ✅ (since 10) | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The testing surface is notably stable across the whole matrix — the version
sensitivity in this handbook lives in the *features under test* (flexible(),
Context), not in the test tools.

## Related

- [Transactions](../data/transactions.md) — commit-boundary testing and why
  `DatabaseTransactions` hides it.
- [Queues](../messaging/queues.md) — the retry/failed-job machinery that
  failure injection exercises.
- [Idempotency](../messaging/idempotency.md) — the interleave test's fixed
  counterpart lives there.
- [Observability](observability.md) — the ids that make incidents reproducible.
- [Locking](../data/locking.md) — scope-contract and TTL-expiry test patterns.
- Roadmap: property-based testing, contract testing across modules.
