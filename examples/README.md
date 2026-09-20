# Verification harness

Executable proofs for the handbook's load-bearing claims
([strategy](../docs/examples.md), [decision 0003](../docs/decisions/0003-example-harness.md)).

- **Stack:** [Orchestra Testbench](https://github.com/orchestral/testbench) + Pest.
  One Testbench major per Laravel major (8→10, 9→11, 10→12, 11→13).
- **Mapping contract:** every chapter block tagged
  `<!-- example: topic/slug -->` corresponds to a test docblock `@example-id
  topic/slug` here. `scripts/check-example-mapping.mjs` enforces both directions.
  Tests encode the chapter's pattern with harness-local fixture classes
  (`tests/Fixtures/`) — the chapters' `App\...` namespaces don't exist here.
- **Environment:** SQLite in-memory, array cache, sync queue by default. Redis-only
  claims call `redisRequired()` and skip when Redis is absent (CI always provides it).

## Run

```bash
composer install
vendor/bin/pest
vendor/bin/pest --group=outbox   # one chapter
```

The CI workflow runs the suite against Laravel 10/11/12/13. Tests that encode
version-gated behavior `markTestSkipped` with a reason; the skip list must stay in
sync with the chapter version tables.

## Layout

```text
tests/
  TestCase.php        # base: migrations, redisRequired()
  Pest.php            # Pest binding
  migrations/         # shared test tables
  <Topic>ExamplesTest.php
```
