---
title: Security
topic: security
status: reviewed
reviewed-against: "Laravel 12.x & 13.x docs, 2026-11"
testbed: examples/tests/SecurityExamplesTest.php
---

# Security

Security in a Laravel application is a short list of disciplines applied
inconsistently at your peril: **validate input, parameterize queries, guard
mass assignment, escape output, protect state-changing routes against CSRF,
keep secrets out of code, and let [authorization](authorization.md) decide who
may act.** None of these are hard; each one, skipped, is a breach class. This
chapter covers the disciplines Laravel doesn't force on you.

## TL;DR

- **Use it (this checklist) when:** the application accepts input, renders
  output, stores secrets, or is reachable — i.e., always.
- **Don't do it:** as a pre-launch audit. Each discipline is a *default
  posture* in code review and tests, not a phase.
- **The classic production incident:** a raw query concatenating a sort column
  from user input (`ORDER BY {$column}`) — parameterization protects values,
  not identifiers, and a column-name injection walks the whole table. Second
  classic: mass assignment turning "update my profile" into "update my
  `is_admin` column".

## Problem

Four breach classes account for most Laravel-specific incidents:

1. **SQL injection** — user input concatenated into SQL. Eloquent's query
   builder parameterizes *values* by default, but `whereRaw`, `orderByRaw`,
   `selectRaw`, and `DB::statement` interpolate whatever you give them — and
   *identifiers* (column/table names) cannot be bound parameters.
2. **Mass assignment** — `$request->all()` into `Model::create()` lets the
   client set any column that isn't guarded, including privilege flags.
3. **XSS** — unescaped user content rendered into HTML (Blade escapes `{{ }}`
   by default; `{!! !!}` and non-Blade rendering don't).
4. **CSRF** — state-changing POSTs without token verification let a third
   party's page ride the victim's session.

Plus the ambient ones: secrets in code/logs, weak hashing choices, and
[authorization](authorization.md) gaps — covered in their own chapters.

## When to use it

- Always — with the posture: every raw SQL fragment gets its provenance
  reviewed, every model declares `$fillable`, every HTML sink gets an escape
  audit, every state-changing web route gets CSRF, every secret comes from the
  environment.

## When NOT to use it (the misplaced efforts)

- **Encrypting what should be authorized.** Encrypting the database column
  doesn't help if any logged-in user can read the endpoint that decrypts it —
  [authorization](authorization.md) first.
- **Security through obscurity.** Renaming admin routes or hiding fields in
  the client adds nothing an attacker can't discover; the server-side rule is
  the security.
- **Rolling your own crypto/hashing.** `Hash::make` (bcrypt/argon2id via the
  framework) and `Crypt` (AES-256 with framework-managed keys) exist; a
  hand-rolled "MD5 + salt" is a vulnerability, not a feature.
- **A WAF instead of parameterized queries.** Filtering layers reduce noise;
  the injection is fixed in the query.

## The simplest correct implementation

Parameterized raw SQL — the discipline for every escape hatch:

<!-- example: security/sql-injection-parameterization -->
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class OrderSearch
{
    /**
     * @param array{column: string, direction: string, customerId: string} $params
     */
    public function query(array $params): array
    {
        // VALUES are bound — never interpolated, never concatenated.
        // IDENTIFIERS (column names) cannot be bound: whitelist them.
        $sortable = ['placed_at', 'total_cents', 'id'];
        $column = in_array($params['column'], $sortable, true)
            ? $params['column']
            : 'placed_at';
        $direction = $params['direction'] === 'asc' ? 'asc' : 'desc';

        return DB::select(
            "SELECT id, status FROM orders WHERE customer_id = ? ORDER BY {$column} {$direction}",
            [$params['customerId']], // bound: the input stays DATA
        );
    }
}
```

The two-part rule: **bind every value, whitelist every identifier.** An
`ORDER BY` built from user input is not safe because "it's just a column
name" — it's safe because you checked it against a list.

## What production requires

### Mass assignment: the guard is a feature, not friction

<!-- example: security/mass-assignment-guard -->
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Profile extends Model
{
    /**
     * The allow-list IS the security boundary: anything not listed here
     * cannot be set through mass assignment — including flags a client
     * should never choose (`is_admin`, `tenant_id`, `password_hash`).
     */
    protected $fillable = ['display_name', 'bio'];
}
```

```php
<?php

use Illuminate\Database\Eloquent\Model;
use App\Models\Profile;

// Default framework behavior: silent discard (a typo in a field name
// vanishes without a trace):
Profile::create(['display_name' => 'Ada', 'password_hash' => 'x']);
// -> 'password_hash' is silently NOT set.

// The stricter posture (enable outside production — testing catches the
// typo AND the injection attempt):
Model::preventSilentlyDiscardingAttributes(! app()->isProduction());
```

```php
<?php

use App\Models\Profile;
use Illuminate\Http\Request;

// Privileged fields are never mass-assigned — set them explicitly:
public function store(Request $request): Profile
{
    // $request->validated() from the FormRequest is the input contract;
    // is_admin comes from the SERVER, not the payload:
    return Profile::create([
        'display_name' => $request->validated('display_name'),
        'bio' => $request->validated('bio'),
        // 'is_admin' => false, // only ever set by explicit, authorized code
    ]);
}
```

With `shouldBeStrict` in the test suite
([Eloquent](../data/eloquent.md) guardrails), a client-supplied `is_admin` in the
payload is a test failure — the breach class dies in CI.

### XSS: escape by default, audit every bypass

Blade's `{{ }}` escapes; `{!! !!}` does not. The audit rule: every
un-escaped sink must justify itself (trusted, sanitized HTML — e.g. a
documented rich-text pipeline) and is reviewed as a security decision. For
APIs, JSON responses escape by construction — the risk moves to the client's
renderer, which is why the *content* contract matters there.

### CSRF: state-changing web routes verify the token

Laravel's CSRF middleware runs on the `web` group by default — the incident
mode is the route you added outside the group (an API-style controller wired
into `web`, or a middleware stack "simplified" during debugging). The
discipline: state-changing routes live in the protected group; genuinely
token-less endpoints (external webhooks!) authenticate by signature instead —
verify the provider's signature header, never just accept the POST.

### Secrets and keys

- **Secrets come from the environment** (`config()` reads only — `env()` is
  config-file-only, the rule the [deployment](../runtime/deployment.md)
  and [Octane](../runtime/octane.md) chapters enforce anyway).
- **`APP_KEY` is cryptographic material** — rotating it invalidates encrypted
  values and sessions; treat its exposure as an incident.
- **Hashing:** passwords via `Hash::make`/`Hash::check` — never `md5`/`sha1`,
  never a home-made scheme; the framework's defaults are maintained for you.
- **Logs are a leak surface:** tokens/passwords never enter log context
  ([observability](../quality/observability.md) `Context::addHidden` where
  available).

## What usually goes wrong

1. **Identifier injection in raw SQL.** *Root cause:* "it's a column name,
   not a value" — concatenated straight from input. *Detection:* the
   injection payload in logs; queries the schema never contained. *Fix:*
   whitelist identifiers (the pattern above); review every `Raw` call in CI
   (grep gate).
2. **Mass-assigned privilege fields.** *Root cause:* `$request->all()` into
   create/update; a new column added without revisiting `$fillable`.
   *Detection:* strict-mode test failures; users with unexpected attribute
   values. *Fix:* fillable allow-lists + explicit setting of privileged
   fields.
3. **The unescaped sink.** *Root cause:* `{!! !!}` for "rich text" without a
   sanitizer. *Detection:* stored scripts firing in other users' sessions
   (stored XSS). *Fix:* escape by default; sanitize pipeline for the rare
   trusted-HTML case.
4. **CSRF-exempt state-changing route.** *Root cause:* webhook copy-paste
   without signature verification, or a route moved out of `web` during
   debugging. *Detection:* cross-origin state changes in access logs.
   *Fix:* signature verification for token-less endpoints; the middleware
   stack reviewed per release.
5. **Secrets in code and logs.** *Root cause:* API keys pasted for
   convenience; exception messages including credentials. *Detection:* the
   secret scanner ([ci-cd](../runtime/ci-cd.md) static stage) or the
   leak report. *Fix:* environment-only secrets; log-context hygiene;
   rotation as the response to exposure.
6. **Unvalidated file uploads.** *Root cause:* "it's just an image" — mime
   type trusted from the client, storage path guessable. *Detection:* web
   shells under the storage disk. *Fix:* validate content (not just
   extension), store outside webroot or via private disks, serve through
   authorized endpoints ([authorization](authorization.md)).

## How to test it

- **Injection (⚙️ `examples/tests/SecurityExamplesTest.php`):** the harness
  executes the injection payload against a vulnerable-construction fixture
  and asserts the parameterized construction refuses it — the breach class,
  demonstrated and closed, deterministically.
- **Mass assignment (⚙️):** assert the guarded attribute is NOT set by
  default, and that the strict mode throws — the `is_admin` attack dies in
  CI.
- **Authorization matrix:** [authorization](authorization.md) chapter's ⚙️
  tests — every endpoint's rules, including the cross-tenant denials.
- **The grep gates:** raw-SQL calls reviewed (`whereRaw|orderByRaw|selectRaw|
  statement`), un-escaped Blade sinks (`{!!`) reviewed — cheap, mechanical,
  honest about coverage.
- **Pen-test posture:** the disciplines above make the *known* classes
  structurally closed; periodic external testing covers what checklists miss.

## How to operate it

- **Dependency and framework updates are security work** — the
  [version policy](../../docs/version-policy.md) support windows are the
  patching contract; running EOL versions is a risk acceptance someone must
  sign ([ci-cd](../runtime/ci-cd.md) audits the surface).
- **Secret rotation runbook:** exposure → rotate (`APP_KEY` rotation has
  data consequences — plan it) → find the leak → fix the sink.
- **Incident classification:** breach classes above map to distinct runbooks
  (injection → audit queries run; XSS → audit sessions affected; mass
  assignment → audit attribute history).
- **3am runbook:** report of "I can see another tenant's data" →
  [authorization](authorization.md) scope test first; "my account did
  something I didn't" → CSRF/signature review; weird rows appearing →
  injection audit of the raw-SQL inventory.

## How it scales

- **The disciplines scale because they're local:** each rule lives at the
  code point it protects — no central "security service" to become a
  bottleneck ([boundaries](../architecture/boundaries.md) layering keeps the
  checks near the entry points).
- **Multi-tenant adds the scope line** to every rule —
  [authorization](authorization.md) and [caching](../data/caching.md) key discipline
  are the same isolation requirement in two layers.
- **The ceiling is process:** checklists decay without enforcement — the
  grep gates, strict-mode tests, and dependency audits
  ([ci-cd](../runtime/ci-cd.md)) are what keep the posture real at
  20 engineers.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Query builder parameterization (values) | ✅ | ✅ | ✅ | ✅ |
| `$fillable` / `preventSilentlyDiscardingAttributes` | ✅ | ✅ | ✅ | ✅ |
| Blade `{{ }}` auto-escaping | ✅ | ✅ | ✅ | ✅ |
| CSRF middleware location | `Http/Kernel` | `bootstrap/app.php` | ✅ slim | ✅ slim |
| `Hash` / `Crypt` facades | ✅ | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The mechanics are stable; the main version fork is where middleware wiring
lives (Kernel on 10, `bootstrap/app.php` on 11+).

## Related

- [Authorization](authorization.md) — who may act; this chapter's disciplines
  assume its decisions.
- [Eloquent at scale](../data/eloquent.md) — the strict-mode guardrails shared with
  mass-assignment protection.
- [API design](api-design.md) — the input/output contracts at the HTTP
  boundary.
- [Deployment](../runtime/deployment.md) — where env/`APP_KEY` discipline
  meets the release flow.
- [CI/CD](../runtime/ci-cd.md) — the scanners and audits that keep the
  posture enforced.
- Roadmap: content-security-policy tuning, upload pipelines.
