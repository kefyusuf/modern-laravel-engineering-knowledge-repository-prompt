---
title: Online DDL
topic: online-ddl
status: draft
reviewed-against: "MySQL 8.0/8.4 & PostgreSQL 16 docs, 2026-11"
---

# Online DDL

Online DDL is the discipline of changing a large table's schema **without
stopping the application that is writing it** — the moment your tables hold
real data and real traffic, `ALTER TABLE` stops being a migration step and
becomes an operations project. This chapter is the deep-dive behind
[deployment](../runtime/deployment.md)'s expand/contract rule: which changes
are cheap, which lock, and the tooling for the ones that don't fit online.

## TL;DR

- **Use it (this discipline) when:** a schema change targets a table with
  meaningful volume or write traffic — the point where "ALTER and hope" risks
  minutes-to-hours of blocking.
- **Don't do it:** by running DDL in a deploy window and trusting luck — the
  lock duration depends on data volume, concurrent load, and the specific
  operation; "it was fast in staging" proves nothing.
- **The classic production incident:** `ALTER TABLE orders ADD COLUMN ...`
  on a 40M-row table — the metadata lock queues behind a long-running
  transaction, and every subsequent query on that table queues behind the
  DDL: a full outage triggered by one "safe" migration.

## Problem

`ALTER TABLE` cost is not one number — it's per-operation, per-engine, and
per-version:

- **MySQL/InnoDB** classifies operations: `INSTANT` (metadata-only, near-free —
  e.g. ADD COLUMN in modern versions), `INPLACE` (rebuilds without blocking
  reads/writes for most of the work — permits/locks vary), `COPY` (full table
  copy under a lock — the killer). The engine's online-DDL table decides;
  your migration must ask for the right algorithm explicitly or verify what
  it got.
- **PostgreSQL** takes a different shape: most `ADD COLUMN` operations are
  cheap (and nullable columns are near-free), but many `ALTER`s take an
  ACCESS EXCLUSIVE lock — and **even a trivial DDL queues behind long-running
  transactions**, so the lock wait, not the DDL, is the outage.
- **The metadata-lock trap (both engines):** any DDL waits for all open
  transactions on the table to finish — one idle-in-transaction session from
  a stuck worker or report blocks the migration, and everything else queues
  behind the migration.

## When to use it

- Any `ALTER` on a table where the operation is not documented-cheap for your
  engine/version, or where write traffic makes even brief locks expensive.
- Adding/removing indexes on large tables (index build time × write load).
- Column type changes, widening, and renames on real data
  ([deployment](../runtime/deployment.md) expand/contract).

## When NOT to use it

- **Small tables** (config, lookups, anything under a few thousand rows) —
  the default migration path is correct; tooling is overhead.
- **Operations the engine documents as INSTANT/cheap** for your version —
  read the engine's table first; don't gh-ost an ADD COLUMN that's metadata-
  only.
- **As a substitute for expand/contract discipline.** Tooling makes a column
  add safe; it doesn't make a rename safe against running code — that's the
  two-release rule ([deployment](../runtime/deployment.md)).

## The cheapest-first ladder

Before reaching for tooling, spend the cheap operations wisely:

1. **Prefer metadata-only operations.** Adding a nullable column with no
   default (MySQL 8.0.12+ `ALGORITHM=INSTANT`; PostgreSQL near-instant) is
   the cheapest schema change you'll make. Design migrations to use them.
2. **Defaults with care:** adding a column *with* a default may be instant or
   a full update depending on engine/version — verify per version's online-
   DDL documentation before assuming.
3. **Index changes:** `CREATE INDEX` online options (`MySQL: ALGORITHM=INPLACE,
   LOCK=NONE`; PostgreSQL: `CREATE INDEX CONCURRENTLY` — which cannot run
   inside a transaction, so it lives outside normal migrations).

## The simplest correct implementation

Explicitness — the migration states its algorithm and the preconditions:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cheap on MySQL 8.0.12+ (INSTANT) and PostgreSQL (near-instant):
        // a nullable column with no default is metadata-only. If your
        // version/engine needs a rebuild, this belongs behind tooling
        // instead — verify before running on the big table.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->after('merchant_id');
        });

        // The access-path index: built online on MySQL (INPLACE,
        // LOCK=NONE); on PostgreSQL it must be CONCURRENTLY — which
        // transactions forbid, so it runs as raw SQL outside the
        // migration transaction:
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_campaign_id_index ON orders (campaign_id)');
    }
};
```

For MySQL, an explicit guard makes the algorithm visible in the failure:

```php
<?php

use Illuminate\Support\Facades\DB;

// Declare the algorithm: if the engine cannot satisfy it online, the ALTER
// FAILS LOUDLY instead of silently blocking the table — a failed migration
// is recoverable; a blocked table is an outage.
DB::statement(
    'ALTER TABLE orders ADD COLUMN campaign_id BIGINT NULL, ALGORITHM=INSTANT'
);

// PostgreSQL's CONCURRENTLY already refuses to run inside a transaction —
// the framework's migration transaction is the loud failure there.
```

The pattern that matters: **declare the algorithm, let the engine refuse
non-online combinations loudly** — a failed migration is recoverable; a
blocked table is an outage.

## What production requires

- **The pre-flight check:** no long-running transactions on the target table
  before starting (`information_schema.innodb_trx` /
  `pg_stat_activity` — kill or wait; the stuck-worker runbooks of
  [Transactions](transactions.md) are the same discipline).
- **The rollback story per operation:** ADD COLUMN rolls back by dropping;
  a CONCURRENTLY index drops with `DROP INDEX CONCURRENTLY`; tooling-created
  tables roll back by the tool's own procedures — written down before
  running.
- **Replication awareness:** online tools and INPLACE rebuilds generate
  heavy binlog/replication load — the replica lag window matters to
  read-your-writes flows ([Transactions](transactions.md)).
- **Disk headroom:** INPLACE rebuilds and tools copy the table — free disk
  ≥ table size is a precondition, not a hope.
- **Timing:** the cheapest operation still benefits from low-traffic windows
  — and from NOT being in the same deploy as the code that uses the new
  column ([deployment](../runtime/deployment.md) sequencing).

## Tooling: when the operation can't be online

For changes the engine can't do without a rebuild-lock (older MySQL
versions, bulk data reshapes), the standard tools copy the table while
syncing changes — the two you'll encounter:

- **gh-ost** — trigger-less, reads the binlog; needs binlog privileges and a
  replica-friendly posture; the modern default for MySQL.
- **pt-online-schema-change** (Percona Toolkit) — trigger-based; works where
  binlog requirements can't be met.

Both share the same mental model: shadow table → copy rows in chunks →
sync deltas → atomic swap. Production requirements for either: disk
headroom, chunk sizing tuned to your write load, throttle hooks
(a replicalag/heartbeat watcher), and the swap performed in a low-traffic
window ([deployment](../runtime/deployment.md)).

### Managed platforms: the platform's lever comes first

On managed databases the platform often provides a better lever than
self-run tooling — check before installing gh-ost:

- **RDS/Aurora MySQL:** Blue/Green Deployments flip a cloned, migrated
  topology instead of an in-place ALTER; Aurora's instant DDL for ADD
  COLUMN differs from community MySQL — verify per engine version.
- **Cloud SQL / Azure:** per-platform online-DDL notes and maintenance
  windows; some platforms disallow the binlog access gh-ost needs (making
  the platform's flow the only viable one).
- **The invariant:** whichever lever you use, the expand/contract
  sequencing and the pre-flight/rollback discipline
  ([deployment](../runtime/deployment.md)) are unchanged — the platform
  changes the tool, never the rules.

## What usually goes wrong

1. **The metadata-lock pileup.** *Root cause:* DDL queued behind a
   long-running transaction; everything else queues behind the DDL.
   *Detection:* connection spikes at migration time; "database is down"
   with the DDL visible in process lists. *Fix:* pre-flight transaction
   check; migrate in low-traffic windows; short transactions as a standing
   rule ([Transactions](transactions.md)).
2. **The assumed-cheap operation that rebuilds.** *Root cause:* defaults,
   type changes, and engine/version differences silently turning INSTANT
   into COPY. *Detection:* migration duration far beyond expectation;
   disk usage jump. *Fix:* declare algorithms explicitly; read the engine's
   online-DDL table per version.
3. **CONCURRENTLY inside a transaction.** *Root cause:* PostgreSQL's
   `CREATE INDEX CONCURRENTLY` wrapped by a framework migration
   transaction — it cannot run there. *Detection:* immediate migration
   failure. *Fix:* raw statement outside transactions (and know that a
   failed CONCURRENTLY leaves an INVALID index to drop).
4. **Tooling without headroom or throttle.** *Root cause:* gh-ost on a
   disk with less free space than the table; chunk sizes tuned for staging.
   *Detection:* disk-full mid-copy (worst case), replica lag spikes. *Fix:*
   pre-flight disk check + throttle hooks + lag monitors.
5. **Deploy-coupled DDL.** *Root cause:* the new code and the ALTER in one
   release, on one big table. *Detection:* deploy-time outage.
   *Fix:* expand/contract sequencing ([deployment](../runtime/deployment.md))
   — DDL expanded ahead, code deployed after, contract later.

## How to test it

- **Rehearse on production-shaped data:** the migration runs against a
  staging copy with production row counts and a synthetic write load — the
  only honest measurement of lock behavior and duration.
- **The pre-flight is scripted:** the transaction check and disk headroom
  check are part of the migration's wrapper, not the operator's memory.
- **Rollback rehearsed:** every online migration ships with its tested
  reverse ([deployment](../runtime/deployment.md) drill discipline).
- The [deployment chapter](../runtime/deployment.md)'s harness claims cover
  the expand/contract mechanics; this chapter's claims are engine behaviors —
  verified per engine/version on staging, stated per version in the chapter.

## How to operate it

- **The migration log:** every schema change on a big table records — table,
  operation, algorithm/tool, duration, peak replica lag, rollback procedure.
  The next migration starts from history, not folklore.
- **Replica lag as the metric:** online DDL's real-time cost shows up as lag;
  alert during the migration window and pause/resume tools on threshold.
- **3am runbook:** migration stuck → is it the metadata lock (find the
  blocking transaction) or the copy (progress tooling)?; outage during DDL →
  killing the DDL is usually correct — it rolls back to the pre-DDL schema;
  disk pressure mid-copy → throttle/pause via the tool, never kill -9.

## How it scales

- **Data volume is the only axis that matters** for DDL choice — the bigger
  the table, the earlier in the design you think "will this be INSTANT?"
- **Table growth forecasts belong in migration review:** a column added to a
  table projected at 100M rows is designed differently from one destined for
  10k ([database performance](database-performance.md) growth awareness).
- **The ceiling:** engines keep making more operations online — the tooling
  layer shrinks over time; the pre-flight/rollback discipline doesn't.

## Version differences

| Behavior | MySQL/InnoDB | PostgreSQL | Notes |
|---|---|---|---|
| ADD COLUMN nullable, no default | ⚠️ INSTANT (8.0.12+; verify per version) | ✅ near-instant | Cheap by design — prefer this shape |
| `CREATE INDEX` online | ✅ INPLACE, LOCK=NONE | ✅ CONCURRENTLY (outside transactions) | PG: failed attempts leave INVALID indexes |
| Statement-level algorithm control | ✅ `ALGORITHM=`, `LOCK=` | n/a | Declare it — fail loudly, don't block silently |
| Online-rebuild tooling | gh-ost, pt-online-schema-change | n/a (no equivalent needed for most ops) | Copy-and-swap model |

Engine capabilities — not Laravel versions — are the variable here; Laravel
10–13 passes DDL through identically. The rows above are engine discipline;
verify the exact INSTANT/INPLACE classification for *your* version's online-
DDL documentation before a big-table change.

## Related

- [Deployment](../runtime/deployment.md) — expand/contract, the two-release
  rule this discipline enforces mechanically.
- [Transactions](transactions.md) — the long-transaction trap behind
  metadata locks.
- [Database performance](database-performance.md) — where indexes and access
  paths justify the DDL in the first place.
- [Testing the failure paths](../quality/testing.md) — rehearsing the
  migration against production-shaped data.
- Roadmap: online DDL on managed platforms (gh-ost vs RDS blue/green).
