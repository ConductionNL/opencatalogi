# Design: opencatalogi-delegate-broadcast-to-or-webhooks

## Architecture Overview

Today, OC's `BroadcastService` is a self-contained HTTP retry engine. It
constructs the broadcast payload, runs a synchronous `while`-loop with
exponential backoff, and returns boolean success once the loop ends. OR's
`WebhookService` is unused by this path even though it already implements the
exact semantics required by FED-OR-001/002 for other event types.

After this change, `BroadcastService` becomes a payload + target-resolution
adapter; OR's `WebhookService` owns dispatch, retry, dead-letter, and
persistence.

```
DirectoryService::syncDirectory ───┐
cron broadcast job ────────────────┤
admin trigger UI ──────────────────┤
                                   ▼
                  BroadcastService::broadcast(url?)
                                   │
                                   │  resolve target list (allowlist + SSRF guard pre-flight)
                                   │  build payload (directory, timestamp, source)
                                   │
                                   ▼
                  WebhookService::triggerWebhookForEvent(
                      'opencatalogi.federation.broadcast',
                      payload,
                      targetUrl
                  )                  (OR side)
                                   │
                                   ▼
                  WebhookLog row (status=pending, next_retry_at=now)
                                   │
              ┌────────────────────┴────────────────────┐
              ▼                                         ▼
      cron OR webhook dispatcher              admin can inspect via OC UI
              │                                         │
              ▼                                         │
      assertSafeOutboundUrl + HTTP POST                 │
              │                                         │
              ▼                                         │
    success ─▶ WebhookLog status=delivered              │
    fail    ─▶ next_retry_at updated per RetryPolicy    │
    max     ─▶ WebhookLog status=dead-letter ───────────┘
```

## Context

- **Spec source**: `openspec/specs/federation/spec.md` (FED-OR-001, FED-OR-002,
  spec migration table line 240).
- **WOO-509 paper-trail comment**: documents the gap between spec and live code
  on 2026-06-29.
- **Upstream dependency**: OR's `WebhookService` is implemented today
  ([apps-extra/openregister/lib/Service/WebhookService.php][ws] lines 792-1192
  for retry pipeline). No OR-side schema changes required.
- **Adjacent migration**: WOO-508 / `register-resolver-service` openspec is
  independent. The resolver simplifies multi-register lookups; broadcast
  delivery does not need it.

[ws]: ../../../../openregister/lib/Service/WebhookService.php

## Key Decisions

### Decision 1 — Single shared webhook entry per OC instance, not per-peer

Each OC instance registers exactly one `Webhook` row at install time (or on
first use), with event `opencatalogi.federation.broadcast`. The `triggerWebhook`
call passes the per-call target URL as part of the payload; OR's dispatcher
uses that URL for the HTTP POST rather than a stored `webhook.url`.

**Why**: per-peer webhooks would mean OC has to manage Webhook lifecycle as
peers come and go from the directory — and listings change frequently in
healthy federations. A single shared row is cheaper and avoids drift.

**Trade-off**: operators lose per-peer rate-limit knobs at the OR config level.
Mitigation: OC's pre-flight SSRF-guard + allowlist already gates which URLs
reach OR; operators tune via OC config keys, not OR webhook policy.

### Decision 2 — Pre-flight SSRF guard stays in OC

`BroadcastService::assertSafeOutboundUrl()` (line 520) runs **before** the
`triggerWebhookForEvent` call. We do not rely on OR-side re-validation to
catch SSRF.

**Why**: defense-in-depth — the same guard caught a series of metadata-IP
attacks during wave-7 hardening (PR #737). Removing it would regress that
posture. OR's own SSRF validation runs at dispatch, but OC owning the pre-flight
keeps the responsibility local to where the URL list is composed.

### Decision 3 — Return-shape compatibility via `BroadcastResult`

`broadcast()` continues to return `array<string, bool>` keyed by target URL,
but the boolean now means "enqueued successfully" rather than "delivered". A
companion method `getBroadcastStatus(targetUrl): BroadcastResult` queries the
WebhookLog so callers (admin UI, cron job) can show delivery status without
polling each target via HTTP.

**Why**: minimize ripple in the 4-5 call sites. The semantic shift is
documented in PHPDoc and release notes; behaviour-sensitive callers can opt
into the richer `BroadcastResult` API.

### Decision 4 — Old retry constants removed without backwards-compatibility

`DEFAULT_MAX_RETRIES`, `MAX_RETRY_WALL_SECONDS`, `CONFIG_MAX_RETRIES`,
`CONFIG_REQUEST_TIMEOUT` and their getters disappear. Operator-facing release
notes call out the change and point to OR's webhook retry policy as the new
tuning knob.

**Why**: keeping shims around encourages operators to tune the wrong layer,
and the spec explicitly forbids app-local retry maths.

## Implementation Sketch

### Phase 1 — Adapter method (no behaviour change yet)

Add `BroadcastService::enqueueBroadcast(string $url, string $directoryUrl):
BroadcastResult`. Initially this method just calls the existing
`sendBroadcastRequest()` and wraps the result; this lets us migrate callers
without flipping the dispatch backend.

### Phase 2 — Wire to OR's WebhookService

`enqueueBroadcast()` switches to calling `WebhookService::triggerWebhookForEvent`.
A guard checks `$this->appManager->isInstalled('openregister')` before
delegating; if OR is absent, fall back to the old synchronous path with a
deprecation log warning. (Pure safety net — OR is a hard dependency in
practice.)

### Phase 3 — Drop the synchronous code

Delete `sendBroadcastRequest()`, the wall-time cap loop, the retry constants
and their config-key resolvers. Cron broadcast job and `broadcast()` public
method now use only the enqueue path.

### Phase 4 — Admin surface

`SetupController` (or a new `FederationController` route) exposes
`GET /api/federation/broadcasts` listing recent WebhookLog rows filtered by
event `opencatalogi.federation.broadcast`. Dead-letter rows are highlighted.
Vue UI under federation settings reads this endpoint.

## Testing Plan

- **PHPUnit**: existing `BroadcastServiceTest` rewritten — happy path is now
  "WebhookService receives the trigger with correct payload", error path is
  "OR absent ⇒ deprecation warning logged + fallback used".
- **Newman Phase 11** (new): configure a peer at `http://does-not-exist.invalid/`
  in nc-fed-2 listings, trigger a broadcast on nc-fed-1, poll
  `/api/federation/broadcasts` until either a `delivered` row appears (peer
  reachable) or `dead-letter` after `webhook.maxRetries` (peer unreachable).
  Asserts the dead-letter promotion happens within OR's policy window.
- **Manual smoke**: admin UI surfaces a dead-letter row visibly; operator can
  re-queue it (next-phase enhancement, not in this change).

## Rollout / Migration

- No data migration. WebhookLog table already exists in OR.
- No occ command required. First broadcast call after upgrade lazily creates
  the shared `Webhook` row if missing.
- Release notes: call out removed config keys + new dead-letter visibility.
- No feature-flag — the change is small enough that bisecting on the merge
  commit is sufficient if a regression appears.

## Open Items

- Confirm `WebhookService::triggerWebhookForEvent` signature on OR's
  development HEAD at implementation start; method name above is an
  approximation pending OR-API review.
- Decide whether to expose a manual "retry now" admin action on dead-letter
  rows in the same change or follow-up. Currently scoped as follow-up.
