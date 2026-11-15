---
title: FrankenPHP standalone
topic: frankenphp
status: reviewed
reviewed-against: "FrankenPHP 1.x docs, 2026-11"
---

# FrankenPHP standalone

FrankenPHP is Caddy with PHP embedded: a single binary (or container) that is
simultaneously the web server, TLS terminator, and PHP runtime. This chapter
covers running it **as the platform itself** — classic mode as an FPM+Nginx
replacement, worker mode for the boot-once latency win, and the embedding
story — plus when the standalone path beats running it through
[Octane](octane.md). The state-discipline rules from the Octane chapter apply
verbatim to worker mode; this chapter covers what's different around it.

## TL;DR

- **Use it when:** you want one binary doing web server + PHP (fewer moving
  parts), automatic HTTPS from Caddy, and the option of worker mode later —
  or you're packaging Laravel as an embeddable/self-contained artifact.
- **Don't use it when:** your FPM+Nginx stack meets every budget and your
  team's operational muscle is Nginx — the swap must buy something real.
- **The classic production incident:** worker mode adopted for the latency,
  state discipline ignored — the exact [Octane](octane.md) cross-request
  leak, now under a different server name.

## Problem

The traditional Laravel serving stack is three processes (Nginx → FPM →
opcache'd PHP) with hand-maintained glue: vhost config, socket permissions,
TLS certificates via certbot, reload choreography. FrankenPHP collapses the
stack into one process where PHP is a first-class citizen — and Caddy brings
automatic HTTPS and modern HTTP features with it. The trade: you adopt a
younger runtime with its own operational learnings, and (in worker mode) the
same long-lived-process state discipline as Octane.

## When to use it

- New deployments where one binary/container beats maintaining Nginx + FPM
  glue, with automatic HTTPS out of the box.
- Edge/embedded packaging: the embed feature compiles the application and
  runtime into a single self-contained binary — Laravel shipped as an
  artifact.
- Teams already invested in Caddy's model (config-as-code, on-demand TLS).

## When NOT to use it

- **Existing FPM stacks meeting every budget.** The swap is operational work;
  do it for a concrete gain, not novelty.
- **Teams with deep Nginx expertise and zero Caddy appetite** — the operational
  learning curve is the real cost, and it's paid at 3am.
- **As an untested drop-in during an incident** — the runtime swap deserves
  the [deployment](deployment.md) drill discipline (health gates, rollback),
  not a mid-outage experiment.

## Serving models (and the Laravel path)

| Model | Shape | Notes for Laravel |
|---|---|---|
| **Classic** | Caddy executes each request like FPM would (no long-lived app) | Drop-in posture: the state discipline of FPM applies; no Octane needed |
| **Worker** | The app boots once per worker; requests reuse it | The Laravel path is **Octane with the FrankenPHP server** (`octane:install --server=frankenphp`) — the officially documented integration; the `runtime/frankenphp` package is the generic Symfony-runtime approach, not the Laravel one |
| **Embed** | The app + runtime compiled into one binary | Single-artifact deployment; the app's operational rules (state, config caching) unchanged |

The verified version facts: FrankenPHP's worker mode keeps the PHP application
bootstrapped between requests (the boot-cost elimination
[Octane](octane.md) describes), and Octane's bundled FrankenPHP binary ships
its own PHP runtime — independent of system PHP
([Octane](octane.md) version table).

## The simplest correct implementation

The worker-mode path for Laravel runs through Octane's integration — which
means the [Octane chapter](octane.md) IS the configuration chapter (state
audit, flush list, `--max-requests`-equivalent recycling, health gates). The
standalone-specific decisions:

```text
1. Choose the artifact:  official Docker image, or the static binary
                         (the binary ships its own PHP — pin it like a
                         runtime dependency; [Octane](octane.md) records the
                         version facts).
2. Choose the mode:      classic (FPM replacement posture) or worker
                         (Octane integration; the state audit is mandatory).
3. TLS:                  Caddy's automatic HTTPS — or terminate upstream and
                         run plain HTTP inside the network.
4. Deploy:               the release flow (deployment chapter) — binary/config
                         swap with health gates; worker restarts on deploy
                         (queue:restart's sibling, same reasoning).
```

## What production requires

- **The state audit — identical to Octane's:** statics and unflushed
  singletons leak across requests in worker mode exactly as under Octane's
  Swoole workers; the leak test ([Octane](octane.md)) is mode-agnostic and
  mandatory here too.
- **Restart discipline:** worker processes are long-lived — deploys restart
  them, `stopwaitsecs`-style supervision applies, and the health gate decides
  traffic ([deployment](deployment.md)).
- **Memory and worker count:** workers ≈ small multiples of CPU threads (the
  upstream guidance), bounded by measured RSS; the per-worker metrics of the
  [Octane](octane.md) ops section apply unchanged.
- **Config caching:** production config caching rules and the `env()`-only-in-
  config law hold in every serving model ([deployment](deployment.md)).

## What usually goes wrong

1. **Worker-mode state leaks.** *Root cause:* identical to the Octane
   incident class — statics, unflushed singletons, per-request data in
   long-lived services. *Detection:* cross-request/cross-tenant data bleed;
   the leak test failing. *Fix:* the Octane chapter's state audit + flush
   list + leak test, verbatim.
2. **Assuming FPM semantics.** *Root cause:* "it's PHP, process per request"
   — wrong in worker mode; per-request teardown assumptions (autoloading
   fresh code, fresh config) don't hold. *Detection:* old code/config serving
   after deploys. *Fix:* restart-on-deploy + config-cache discipline.
3. **PHP version drift in the binary.** *Root cause:* the bundled runtime
   moves independently of system PHP; extensions differ from the FPM stack.
   *Detection:* extension/platform-require errors after binary upgrades.
   *Fix:* pin and monitor the bundled version as a dependency; CI matrix
   parity ([ci-cd](ci-cd.md)).
4. **TLS assumptions.** *Root cause:* automatic HTTPS is on by default in
   Caddy's model — behind a load balancer that already terminates TLS, it
   tries to solve certificates it can't. *Detection:* certificate errors in
   logs. *Fix:* explicit local/HTTP config when terminating upstream.
5. **Skipping the drill.** *Root cause:* "it's just a web server" — the
   runtime swap deploys without rehearsal. *Detection:* the first real
   deploy doubles as the first test. *Fix:* the deployment chapter's drill
   before cutover; rollback path kept warm.

## How to test it

- **The state-leak test is the gate** ([Octane](octane.md)): two simulated
  requests, one worker, no cross-tenant bleed — it passes in classic mode
  trivially and is the whole ballgame in worker mode.
- **Soak for memory:** N thousand requests against worker mode; RSS bounded.
- **Deploy drill:** the release flow rehearsed against the FrankenPHP
  artifact (binary swap + worker restart + health gate + rollback) — the
  same bar any serving stack must clear.
- The framework-behavior claims this handbook makes have their ⚙️ coverage in
  the harness across the matrix; serving-stack claims are verified in the
  drills above.

## How to operate it

- **Per-worker metrics:** RSS, request rate, p95 — the [Octane](octane.md)
  ops section, applied to the FrankenPHP workers.
- **Binary/runtime upgrades are runtime upgrades:** changelogs read, matrix
  re-run, deploy-drilled — not silent apt-style updates.
- **Caddy config as code:** reviewed and versioned like every other
  infrastructure definition.
- **3am runbook:** data bleed → the state audit (it's the Octane runbook);
  memory → worker recycling + accumulator hunt; TLS errors → certificate
  model review (automatic vs upstream termination).

## How it scales

- **Classic mode scales like FPM** (process count vs CPU); **worker mode
  scales like Octane** — workers × requests-per-second-per-worker, with the
  same blocking-work caveats ([queues](../messaging/queues.md) for the heavy
  work).
- **The embed/single-binary path** scales as an *artifact* story — deploy
  units shrink to one file; the operational discipline (state, config,
  health) is what you scale with it.
- **Beyond one box:** load balancing + the health gates — the serving stack
  is replaceable, the flow isn't ([deployment](deployment.md)).

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Octane's FrankenPHP server (`octane:install --server=frankenphp`) | ✅ (Octane 2.x, ≥10.10.1) | ✅ | ✅ | ✅ |
| FrankenPHP runtime added to Octane | ✅ (Dec 2023) | ✅ | ✅ | ✅ |
| Bundled binary ships its own PHP | ✅ (8.4 at the time of writing, 2026-11) | ✅ | ✅ | ✅ |
| Minimum PHP (framework) | 8.1 | 8.2 | 8.2 | 8.3 |

The Laravel integration surface is Octane's — FrankenPHP standalone is
framework-agnostic infrastructure whose own version facts (bundled PHP,
worker-mode behavior) are tracked on the upstream docs, not by Laravel
versions.

## Related

- [Octane](octane.md) — the state discipline and worker ops this chapter
  inherits; the official Laravel integration path.
- [Deployment](deployment.md) — the drill, health gates, and rollback the
  runtime swap must clear.
- [Queues](../messaging/queues.md) — heavy work stays on queues in every
  serving model.
- [CI/CD](ci-cd.md) — the matrix that must stay green when the bundled PHP
  moves.
- Roadmap: Mercure/streaming patterns, embedded distribution pipelines.
