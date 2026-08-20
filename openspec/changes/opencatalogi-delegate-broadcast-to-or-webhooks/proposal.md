---
kind: code
depends_on: []
---

# Proposal: opencatalogi-delegate-broadcast-to-or-webhooks

## Summary

Refactor `BroadcastService::sendBroadcastRequest()` so that outbound federation
broadcasts are delivered via OpenRegister's `WebhookService` instead of an
app-local synchronous retry loop. This brings the live code into conformance
with `federation/spec.md` requirements **FED-OR-001** (retry policy follows OR
webhooks) and **FED-OR-002** (dead-letter behaviour managed by OR), and removes
the duplicate retry-maths flagged in the federation spec migration table.

## Motivation

`openspec/specs/federation/spec.md` declares two requirements that the current
implementation does not honour:

- **FED-OR-001** ("federation outbound retry follows OR webhook retry policy"):
  delay, jitter and dead-letter threshold MUST come from OR's outbound webhook
  retry policy. opencatalogi MUST NOT re-implement the schedule.
- **FED-OR-002** ("dead-letter behaviour for permanently failing federation
  pushes"): when retries are exhausted, the push MUST be moved to a dead-letter
  state per OR's outbound webhook policy. opencatalogi MUST NOT define its own
  dead-letter logic.

The spec migration table at `federation/spec.md` line 240 explicitly marks
"any PHP code in BroadcastService computing delay, jitter, or backoff interval"
as REMOVED — already a known gap.

The live state ([lib/Service/BroadcastService.php][bs]) violates both:

[bs]: ../../../lib/Service/BroadcastService.php

- `sendBroadcastRequest()` (line 331) runs a synchronous `while`-loop with
  `sleep($attempt * 2)` exponential backoff, capped by
  `MAX_RETRY_WALL_SECONDS = 90` and `broadcast_max_retries` (default 3).
- No integration with OR's `WebhookService` — OR has `scheduleRetry()`,
  `calculateNextRetryTime()`, `calculateRetryDelay()` based on each webhook's
  configured `RetryPolicy`. BroadcastService never reaches that pipeline.
- No dead-letter pad — definitive failures only call `logger->error()`; there
  is no persistent failed-broadcast store, no cron-rescheduled retry pickup,
  no admin-visible failed-queue.

This was validated under WOO-509 against the development HEAD on 2026-06-29
and recorded as a follow-up to the federated-search rollout (WOO-493 AC #8).

## Goals

1. Replace the in-process retry loop in `BroadcastService::sendBroadcastRequest()`
   with a single call that enqueues a webhook delivery via OR's
   `WebhookService`.
2. Re-use OR's persistence + cron-driven retry pickup so a broadcast survives
   PHP-FPM restarts and respects OR's retry policy uniformly.
3. Surface dead-letter state in OC via OR's existing `WebhookLog` table — both
   programmatically (`/api/federation/broadcasts/dead-letter`) and in a small
   admin panel under federation settings.
4. Delete the app-local retry constants
   (`DEFAULT_MAX_RETRIES`, `MAX_RETRY_WALL_SECONDS`, `broadcast_max_retries`,
   `broadcast_request_timeout`) and their getters once the delegation lands.

## Non-Goals

- Re-designing OR's WebhookService itself — this change consumes the existing
  API surface; any retry-policy schema additions live in OR.
- Migrating other OC outbound-HTTP paths to webhooks (DirectoryService's
  pull-sync, PublicationService's federated reads). Only the broadcast push
  path is in scope.
- Backwards compatibility shims for the old config keys. The change ships in a
  minor release with release-notes calling out the removal; operators only
  needed the keys for emergency throttling, which is what OR's webhook policy
  now governs.

## High-Level Approach

A new lightweight method on `BroadcastService` builds the payload
(`directory`, `timestamp`, `source`) and the User-Agent header, then calls
`WebhookService::triggerWebhookForEvent('opencatalogi.federation.broadcast',
$payload, $targetUrl)` (exact signature TBD against OR's resolver-service
landing). OR enqueues a `WebhookLog` row, the cron job picks it up, the same
`assertSafeOutboundUrl()` guard runs at OR-side dispatch (existing wave-12
hardening), and dead-letter promotion happens automatically when
`webhook.maxRetries` is reached.

The `broadcast()` public method's return shape stays `array<string, bool>` but
its semantics change from "delivered synchronously" to "enqueued for delivery"
— a small `BroadcastResult` object is added so callers can distinguish the
two. Existing consumers (`DirectoryService::syncDirectory()` admin trigger,
cron broadcast job) accept "enqueued" as success since OR is responsible for
the actual wire-time guarantee.

## Open Questions

- Does OR's resolver-service (tracked under WOO-508 and the
  `register-resolver-service` openspec change) need to land first so we can
  type-safely resolve the webhook config? The current best-guess is no — OR's
  WebhookService is registered today; only multi-tenant resolution depends on
  the resolver change.
- Should we add a new `Webhook` entry per peer-target on first broadcast, or
  drive the dispatcher via a single shared `Webhook` and per-call target URL?
  The latter is cheaper to ship; the former gives operators per-peer rate
  limiting. Decision deferred to `design.md`.

## Effort Estimate

5-8 SP. Single sprint feasible once OR-side dependencies (WebhookService
admin/CLI surface for OC to enqueue against) are confirmed.

## Out-of-Scope (Successors)

- A migration assistant `occ opencatalogi:broadcast:drain-legacy` that flushes
  any in-flight retries from the synchronous loop on the upgrade boundary.
  Only relevant if the upgrade lands while a multi-day broadcast retry is
  outstanding — extremely unlikely given the 90 s wall-cap.
