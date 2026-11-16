---
title: Distributed tracing
topic: tracing
status: draft
reviewed-against: "OpenTelemetry PHP docs, 2026-11"
---

# Distributed tracing

Tracing is [observability](observability.md)'s third leg: where logs answer
"what happened here?" and metrics answer "how much?", **spans answer "what
happened across the whole path?"** — the request that became a queue job that
called a downstream API that timed out, visible as one connected tree. This
chapter is the hand-off the observability chapter points at: when traces pay,
what OpenTelemetry (OTel) looks like in Laravel today, and the span
discipline that keeps traces from becoming expensive noise.

## TL;DR

- **Use it when:** flows cross process boundaries — request → queue →
  downstream service — and correlating them from logs alone
  ([observability](observability.md)) has stopped being fast enough.
- **Don't do it when:** the app is single-process and logs+metrics already
  resolve incidents — tracing's infra cost and span discipline need a
  consumer.
- **The classic production incident:** "the checkout is slow" with three
  teams' logs showing green — the latency lives in the *gap between*
  systems, invisible to every team's own metrics. A trace is the only
  instrument that shows the gap.

## Problem

Correlated logs ([observability](observability.md)) share `request_id`, but
the correlation is manual: an engineer greps the request log, greps the job
log, greps the downstream service's log, and reconstructs the path by hand.
At one hop, that's a minute. At four hops with fan-out
([modular monolith](../architecture/modular-monolith.md),
[EDA](../architecture/eda.md)), it's the incident's first hour.

Tracing makes the reconstruction automatic: each unit of work is a **span**
with a trace id, a parent, timings, and attributes; the ids propagate across
hops (HTTP headers, queue payload metadata), and the backend stitches the
tree. The instrumentation standard is **OpenTelemetry** — vendor-neutral,
with a PHP SDK, an auto-instrumentation extension, and Laravel integrations
(the official `open-telemetry/opentelemetry-laravel` package, plus community
packages like `keepsuit/laravel-opentelemetry`).

## When to use it

- Multi-hop flows: request → [queue](../messaging/queues.md) → downstream
  HTTP → [outbox](../messaging/outbox.md) → consumer.
- Latency budgets owned across teams — the trace shows whose span owns the
  slow part.
- Debugging intermittent issues where logs alone don't localize the gap.

## When NOT to use it

- **Before logs and metrics work.** Tracing without the observability
  chapter's foundation is expensive data with no query habits.
- **Single-process apps with simple flows** — correlated logs answer
  everything; tracing adds infrastructure without a question it answers.
- **As an uptime monitor.** Traces sample; sampling misses are expected —
  uptime is metrics' job ([observability](observability.md)).

## The simplest correct implementation

OTel's Laravel path: the SDK + the official Laravel instrumentation
package, exporting to any OTLP-compatible backend (Jaeger, Grafana Tempo,
a vendor collector):

```text
composer require open-telemetry/opentelemetry-laravel   # official instrumentation

# .env — the OTel-standard variables, read by the package:
OTEL_SERVICE_NAME=checkout-api
OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=0.1        # head-sampling: keep ~10%, tune per flow
```

Auto-instrumentation then covers HTTP requests, DB queries, and queue jobs
(span creation + context propagation) without per-route code. For
extension-less environments the SDK runs in pure PHP; the `otel` PHP
extension accelerates it. Community alternatives
(`keepsuit/laravel-opentelemetry`, LaraOTel) wrap the same SDK with more
Laravel-specific defaults.

The `traceparent` header is the contract: the instrumentation reads and
writes it automatically — inbound requests continue remote traces, outbound
HTTP calls continue yours (W3C Trace Context).

The one piece auto-instrumentation cannot know: **which spans matter**. The
discipline that makes traces useful is naming and attributing the spans
that map to the domain — not instrumenting everything equally.

## What production requires

### Propagation across the queue hop

The request→job hop is where naive setups break: a queued job is a new
process — the trace context must travel in the payload metadata and be
restored at processing time. Laravel's instrumentation covers the queue
transport; the verification is the
[observability](observability.md) claim promoted to a trace assertion:
**one trace contains the request span AND the job span AND the downstream
span** — the connected tree is the deliverable, not "traces exist".

### The span discipline

- **Span per boundary, not per function:** HTTP request, queue job, HTTP
  call to a third party, [outbox](../messaging/outbox.md) relay batch. A
  span per method is noise.
- **Attributes carry the domain:** `tenant_id`, `order_id`, attempt counts —
  the same keys the [observability](observability.md) chapter established,
  now on spans. High-cardinality values (raw ids as metric labels) are
  fine as span attributes — traces are queried per instance, not
  aggregated.
- **Errors recorded on the failing span** with the exception — the trace
  becomes the runbook's first artifact.
- **Sampling decides the bill:** head sampling (a percentage at trace
  start — keep 100% of errors via the parent-based rules) is the default
  lever; tail sampling (keep all slow/error traces at the collector) is the
  upgrade when the budget matters
  ([cost attribution](observability.md#cost-attribution--the-observability-bill-owned)).

### The rollout

1. Report/deploy in a **staging-first** posture: instrument, ship to a test
   backend, verify the trees connect (request→job→downstream).
2. **Production at low head-sampling** (1–10%) + 100% error visibility.
3. Raise sampling on the flows that answer open questions — sampling is a
   product decision per flow ([testing](../quality/testing.md)'s "assert
   state" philosophy applies to telemetry too: sample by question).

## What usually goes wrong

1. **Disconnected trees.** *Root cause:* context not propagating across the
   queue hop (the payload stripped it) or across an HTTP client the
   instrumentation doesn't cover. *Detection:* traces that start and end
   alone. *Fix:* propagation checked per hop; custom clients instrumented
   manually.
2. **The invisible hop.** *Root cause:* a hand-rolled HTTP client or a
   wrapper around an SDK that doesn't auto-instrument. *Detection:* the
   trace tree has a gap exactly where the latency is. *Fix:* manual span
   creation at that boundary — the discipline's whole point.
3. **Sampling misses the incident.** *Root cause:* head sampling dropped
   the one trace that mattered. *Detection:* "the trace isn't there"
   during an incident. *Fix:* parent-based error-preserving rules; tail
   sampling when the budget allows.
4. **Span spam.** *Root cause:* instrumenting loops (a span per item per
   row). *Detection:* the bill, and backends choking on span volume.
   *Fix:* spans per boundary; batch work gets one span with item counts as
   attributes.
5. **Attributes as a dumping ground.** *Root cause:* whole models dumped
   into span attributes — PII and cardinality explosions. *Detection:*
   backend cost review ([cost attribution](observability.md#cost-attribution--the-observability-bill-owned)).
   *Fix:* ids and enums only; PII rules identical to logging.

## How to test it

- **The connected-tree test:** a feature test with the tracer exporting
  in-memory — assert the resulting trace contains the request span, the
  job span, and the downstream span, with correct parent links. The
  propagation claim, executed in-process (the official instrumentation's
  test utilities support in-memory exporters).
- **Cross-process verification happens in staging:** the queue hop's
  context survives the real worker — the trace tree spans processes there,
  verified per release alongside the [preview environment](../runtime/preview-environments.md)
  drills.
- **Sampling verification:** assert error traces are kept at the configured
  rate (the error-visibility contract).

## How to operate it

- **The trace-first runbook shift:** incidents start from a slow/error
  **trace**, with logs attached to spans — the
  [observability](observability.md) runbook's step zero becomes "open the
  trace".
- **Cardinality and cost reviews:** span counts per service per deploy —
  the [cost attribution](observability.md#cost-attribution--the-observability-bill-owned)
  discipline applied to the newest pipeline.
- **3am runbook:** "slow checkout" → the trace, find the widest span or the
  gap → that span's owner owns the fix; "works but slow for one tenant" →
  span attributes filter by tenant, the outlier trace is the evidence.

## How it scales

- **The OTel collector scales independently** — batching, queueing, and
  tail-sampling happen at the collector; app overhead stays bounded by
  sampling, not backend load.
- **Instrumentation scales via auto-instrumentation + a small manual
  layer** at domain boundaries — the [boundaries](../architecture/boundaries.md)
  layering tells you exactly where those are.
- **The ceiling:** traces answer "where in the path"; they don't answer
  "why is the database engine slow" ([database performance](../data/database-performance.md)
  plans) — tracing complements, never replaces, the per-layer tools.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Official OTel Laravel instrumentation support | ⚠️ (per package release) | ✅ | ✅ | ✅ |
| Queue context propagation (framework metadata) | ✅ | ✅ | ✅ | ✅ |
| W3C Trace Context propagation (HTTP layer) | ✅ (via instrumentation) | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The OTel PHP ecosystem (SDK + Laravel instrumentation) evolves on its own
release train, independent of Laravel's — its support matrix is checked per
adopted version, and the ⚠️ marks exactly that: verify the package supports
your Laravel before committing. The underlying propagation standard (W3C
Trace Context) is stable everywhere.

## Related

- [Observability](observability.md) — the logs+metrics foundation; tracing is
  its third leg.
- [Queues](../messaging/queues.md) — the hop where propagation most often
  breaks.
- [The modular monolith](../architecture/modular-monolith.md) — the multi-team
  flows that make traces the shared evidence.
- [Cost attribution](observability.md#cost-attribution--the-observability-bill-owned)
  — the sampling discipline that keeps tracing affordable.
- Roadmap: tail-sampling policy patterns, trace-based canary analysis.
