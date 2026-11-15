---
title: Streaming and real-time
topic: mercure-streaming
status: reviewed
reviewed-against: "Laravel 12.x & Mercure docs, 2026-11"
---

# Streaming and real-time

Real-time delivery means the server pushes updates to connected clients
instead of clients polling. Laravel's surface for it is wider than one tool —
**broadcasting (WebSockets via Reverb or a hosted service), Server-Sent
Events (SSE) over streaming responses, and Mercure as a protocol/hub** — and
the right choice depends on direction (server→client vs bidirectional),
infrastructure appetite, and the long-lived-connection discipline the
[Octane](octane.md) chapter already established. This chapter is the decision
map plus the push-path hygiene that every option shares.

## TL;DR

- **Use it when:** the product genuinely needs sub-second updates —
  collaborative editing, live dashboards, chat, progress streams — and
  polling's cost or latency is measured to be a problem.
- **Don't do it when:** "live-ish" suffices — a 5-second poll of a cheap
  endpoint ([caching](../data/caching.md)) is simpler, scales horizontally,
  and never holds a connection open.
- **The classic production incident:** every browser tab holds an open
  connection through PHP-FPM's worker pool — a few hundred concurrent tabs
  exhaust the workers and the *whole site* dies, not just the realtime
  feature. Real-time is an infrastructure decision, not a route.

## Problem

Push delivery moves three problems into your architecture:

1. **Connection lifetime** — long-lived connections consume a worker or a
   dedicated hub process; the serving model ([Octane](octane.md),
   [FrankenPHP](frankenphp.md)) determines capacity.
2. **Delivery semantics** — WebSockets/SSE deliver "at best effort over an
   open connection": what happens to a message emitted while a client is
   disconnected? (Nothing, unless you persist — the
   [idempotency](../messaging/idempotency.md)/[outbox](../messaging/outbox.md) thinking
   reappears.)
3. **Authorization on push** — a socket/SSE connection is authenticated once;
   every pushed payload must still be scoped to the subscriber
   ([authorization](../interfaces/authorization.md)).

## The options, mapped

| Option | Transport | Best for | Know |
|---|---|---|---|
| **Broadcasting + Reverb** (Laravel's WebSocket server) | WebSocket (Echo client) | Bidirectional-ish UIs, presence, Laravel-idiomatic events | Long-lived connections → its own process, not FPM workers |
| **SSE (streamed responses)** | One-way server→client over HTTP | Progress streams, notifications, dashboards | Every open SSE holds a PHP worker (or Octane coroutine); timeouts/proxies matter |
| **Mercure hub** | Server→client SSE via a dedicated hub | Decoupled publishing: the app POSTs updates to the hub, the hub fans out; auto-reconnect + `Last-Event-ID` replay | The hub is separate infrastructure (auth via JWT, topic-based authorization) |
| **Polling** | Plain HTTP | Live-ish on cheap endpoints | The baseline every push option must beat |

Laravel's broadcasting layer is transport-agnostic at the event level: the
same `ShouldBroadcast` event works over Reverb, Pusher, or a custom driver —
the topology decision ([broker topologies](../messaging/broker-topologies.md)) sits below
it.

## The simplest correct implementation

Server→client updates over Mercure, decoupled through the hub:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class DashboardPublisher
{
    /**
     * The app POSTS the update to the hub; the hub fans out to every
     * authorized subscriber. The app never holds a browser connection —
     * that's the hub's job (and its scaling story). The PUBLISHER
     * credential is separate from the subscriber's JWT — a default hub
     * 401s any publish without it.
     */
    public function publish(array $update): void
    {
        Http::asForm()
            ->withToken(config('services.mercure.publisher_jwt'))
            ->post(config('services.mercure.url'), [
                'topic' => "dashboards/{$update['tenant_id']}",
                'data' => json_encode($update),
            ])->throw();
    }
}
```

```php
<?php

// The client subscribes with EventSource (browser-native SSE), scoped by
// topic; the hub enforces topic authorization via its JWT rules.
//
//   const es = new EventSource('/.well-known/mercure?topic=dashboards/7');
//   es.onmessage = (e) => render(JSON.parse(e.data));
//
// The authorization JWT comes from your Laravel backend — the hub trusts
// your signature, not your session.
```

The publishing path's reliability is explicit: if the update must not be
lost, it's an [outbox](../messaging/outbox.md) fact relayed to the hub —
not a fire-and-forget POST.

## What production requires

- **Capacity for the connections:** SSE/WS connections live outside the
  normal request pool — size the hub/workers for *connection count*, and
  keep PHP request workers out of the connection path (Mercure's hub or
  Reverb's process, never FPM).
- **Scoping per subscriber:** topic-based authorization — a client must only
  receive topics it may see (the tenant-scope line drawn at the hub —
  [row-level security](../data/row-level-security.md)'s isolation requirement
  at the push layer).
- **Reconnection semantics:** EventSource auto-reconnects with
  `Last-Event-ID`; the hub replays what the client missed (Mercure's
  built-in) or your app re-sends the current state (poll-on-reconnect as the
  safety net).
- **Backpressure:** a slow client must not slow the publisher — hubs buffer
  or drop per client; know your hub's behavior before promising delivery.
- **Monitoring:** connected-clients count, message rate, hub health — the
  realtime feature's four signals ([observability](../quality/observability.md)).

## What usually goes wrong

1. **SSE through the request pool.** *Root cause:* a streamed response
   holding an FPM worker per connection. *Detection:* worker exhaustion with
   modest concurrent tabs; the whole site slows. *Fix:* the hub/streaming
   server owns connections (Mercure/Reverb), or Octane with explicit
   coroutine discipline.
2. **Missing messages on reconnect.** *Root cause:* fire-and-forget push; a
   client that disconnected for 3 seconds misses 3 seconds of events.
   *Detection:* dashboards inconsistent until refresh. *Fix:* replay
   (Mercure's `Last-Event-ID`) or state-refresh-on-reconnect.
3. **Unscoped topics.** *Root cause:* topic names guessed by clients
   (`dashboards/{id}` without authorization). *Detection:* a tenant reading
   another tenant's updates. *Fix:* hub-side topic authorization via signed
   JWTs; the backend issues the subscription token.
4. **The publisher that dies silently.** *Root cause:* the hub POST failing
   (hub down) without alerting — updates stop, nobody notices. *Detection:*
   the dashboard freezes; no error surfaced. *Fix:* `throw()` on publish +
   publish-failure metrics; the durable path for must-not-lose updates
   ([outbox](../messaging/outbox.md)).
5. **Proxy buffering killing SSE.** *Root cause:* Nginx/CDN buffering the
   stream. *Detection:* events arrive in bursts, minutes late. *Fix:*
   `X-Accel-Buffering: no` / proxy streaming config — a deployment-level
   ([deployment](deployment.md)) concern.
6. **Broadcasting auth assumed.** *Root cause:* private channels assumed
   secure because "private" is in the name — the channel authorization
   callback must actually verify membership/tenancy. *Detection:* clients
   subscribing to other tenants' channels. *Fix:* the authorization callback
   is a policy call ([authorization](../interfaces/authorization.md)).

## How to test it

- **The publish contract:** the publisher's HTTP call to the hub — assert
  endpoint, topic, payload shape (Http::fake in the app's suite).
- **Authorization at the subscription boundary:** the JWT/authorize callback
  denies a foreign tenant's topic — the policy test pattern
  ([authorization](../interfaces/authorization.md)) at the push layer.
- **Replay behavior:** a hub feature — verified in staging against the real
  hub (reconnect with `Last-Event-ID`, assert missed events arrive).
- Connection-count behaviors are load tests, not unit tests — the soak
  discipline of [Octane](octane.md) applies to whichever server holds the
  connections.

## How to operate it

- **Connection telemetry:** active connections, subscribe rate, message
  rate, reconnect rate — reconnect spikes mean client-side breakage or hub
  instability.
- **Hub as infrastructure:** its health, memory, and restart discipline are
  first-class ([deployment](deployment.md) drills — the hub restarts, the
  clients reconnect, the replay covers the gap: verify that story).
- **3am runbook:** dashboard frozen → publisher failing (its metrics) or hub
  down (its health); cross-tenant updates → the subscription authorization
  callback; slow delivery → proxy buffering first.

### Presence systems

"Who is online right now" is the classic realtime adjunct, and the hub
patterns cover it — with one honesty rule: **presence is a derived,
ephemeral state**, not durable data.

- **Broadcasting's presence channels** (`PresenceChannel`, Echo) — the
  framework joins/authenticates the user into the channel and broadcasts
  join/leave events; your code maintains the roster in memory or Redis
  ([redis](../data/redis.md)) with a TTL.
- **Mercure path:** periodic heartbeats per client (or hub-level connection
  events), a sweeper expiring stale entries, and the roster published as a
  topic the clients subscribe to.
- The failure mode to design for: the roster drifting (a crashed client
  that never sends "leave"). TTL + sweeper is the fix; never store presence
  as truth — it's a heartbeat view of active connections, and it disappears
  on restart by design.

## How it scales

- **The hub scales; the app doesn't hold connections** — Mercure/Reverb
  horizontally, the Laravel app untouched by connection count.
- **Fan-out cost grows with subscribers-per-topic** — topic design (coarse
  topics + client-side filtering vs fine topics + hub routing) is the
  scaling lever; measure before splitting.
- **The ceiling:** bidirectional heavy collaboration moves you toward
  dedicated realtime systems — the hub pattern covers the server-push
  majority honestly.

## Version differences

| Behavior | Laravel 10 | Laravel 11 | Laravel 12 | Laravel 13 |
|---|---|---|---|---|
| Broadcasting (`ShouldBroadcast`, Echo) | ✅ | ✅ | ✅ | ✅ |
| Laravel Reverb (first-party WebSocket server) | ❌ | ✅ (shipped with 11) | ✅ | ✅ |
| Streamed responses (Symfony `StreamedResponse`) | ✅ | ✅ | ✅ | ✅ |
| Mercure hub integration | ✅ (via packages/hub HTTP API) | ✅ | ✅ | ✅ |
| Minimum PHP | 8.1 | 8.2 | 8.2 | 8.3 |

The broadcast abstraction is stable across the matrix; the version fork is
Reverb's arrival in Laravel 11 (before it, first-party WebSockets meant a
hosted provider).

## Related

- [Octane](octane.md) — the long-lived-connection discipline for any
  in-process streaming.
- [Queues](../messaging/queues.md) / [The transactional outbox](../messaging/outbox.md)
  — the durable paths updates flow through before reaching a hub.
- [Authorization](../interfaces/authorization.md) — subscription and topic
  rules.
- [Observability](../quality/observability.md) — the realtime feature's
  signals.
- Roadmap: WebSocket scaling topologies.
