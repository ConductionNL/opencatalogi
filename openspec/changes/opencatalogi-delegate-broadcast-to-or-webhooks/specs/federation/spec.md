# Federation spec delta — opencatalogi-delegate-broadcast-to-or-webhooks

## Modified Requirements

### Requirement: broadcast delegates to OR webhooks (FED-OR-001)

OpenCatalogi MUST deliver federation broadcasts via OpenRegister's
`WebhookService` and MUST NOT implement an app-local retry schedule. The
existing `BroadcastService::sendBroadcastRequest` synchronous `while`-loop
(exponential backoff, `MAX_RETRY_WALL_SECONDS`, `broadcast_max_retries`) MUST
be removed; outbound delivery, retry timing, jitter, and the dead-letter
threshold MUST come from the `Webhook` row's `RetryPolicy` as enforced by OR's
`WebhookService::scheduleRetry()`.

This requirement supersedes the existing FED-OR-001 statement (which is
currently *Not Implemented*) once this change merges, and removes the entries
in the spec migration table that flagged backoff maths as "REMOVED" in
aspiration.

#### Scenario: enqueue replaces synchronous dispatch
- **GIVEN** an OC instance with OR installed
- **AND** a target URL that passes the pre-flight SSRF guard
- **WHEN** `BroadcastService::enqueueBroadcast($url, $directoryUrl)` is called
- **THEN** `WebhookService::triggerWebhookForEvent(
    'opencatalogi.federation.broadcast', payload, $url)` is called exactly once
- **AND** a `WebhookLog` row is persisted with `status = pending` and
  `next_retry_at` per OR's policy
- **AND** the synchronous `while`-loop in `sendBroadcastRequest` is not invoked

#### Scenario: pre-flight SSRF guard short-circuits before delegation
- **GIVEN** a target URL pointing at a metadata IP or an unallowlisted private
  IP
- **WHEN** `enqueueBroadcast` is called
- **THEN** `assertSafeOutboundUrl()` throws **before** any OR call
- **AND** no `WebhookLog` row is created for the rejected URL

### Requirement: broadcast pre-flight SSRF guard (FED-OR-001)

`BroadcastService` MUST validate every outbound URL through
`assertSafeOutboundUrl()` **before** delegating to OR. OR's own SSRF validation
at dispatch time is defence-in-depth, not the primary check. The OC-local
allowlist (`local_federation_hosts` app-config key) MUST continue to govern
dev/test exceptions.

#### Scenario: allowlist permits dev http hosts
- **GIVEN** `local_federation_hosts` includes `nc-fed-1,nc-fed-2`
- **WHEN** `enqueueBroadcast("http://nc-fed-2/...", ...)` is called
- **THEN** pre-flight succeeds even though the scheme is http
- **AND** delegation to OR proceeds

#### Scenario: scheme/host rejection happens locally
- **GIVEN** no allowlist override matches the URL
- **WHEN** `enqueueBroadcast("http://example.org/...", ...)` is called
- **THEN** pre-flight rejects with the existing SSRF error
- **AND** no OR call is made

### Requirement: no app-local retry maths (FED-OR-001)

`lib/Service/BroadcastService.php` MUST NOT contain code that computes a retry
delay, jitter window, max-attempt count, or wall-clock retry cap. All such
values MUST be governed by the `RetryPolicy` of the `Webhook` row used for
`opencatalogi.federation.broadcast` events.

#### Scenario: legacy constants are removed
- **GIVEN** the source after this change
- **WHEN** `lib/Service/BroadcastService.php` is read
- **THEN** none of `DEFAULT_MAX_RETRIES`, `MAX_RETRY_WALL_SECONDS`,
  `CONFIG_MAX_RETRIES`, `CONFIG_REQUEST_TIMEOUT`, `getMaxRetries`,
  `getRequestTimeout` are present
- **AND** no `sleep(...)` call exists in any retry path

### Requirement: dead-letter visibility (FED-OR-002)

OpenCatalogi MUST NOT define its own dead-letter store. Dead-letter state for
federation broadcasts MUST be the `WebhookLog` rows OR produces when the
configured retry policy is exhausted. OpenCatalogi MUST expose those rows to
admins via `GET /api/federation/broadcasts` and a settings UI surface.

#### Scenario: dead-letter row is admin-visible
- **GIVEN** an unreachable peer URL has been broadcast to and OR's retry
  policy has been exhausted
- **WHEN** an admin requests `GET /api/federation/broadcasts`
- **THEN** the response includes a row with `status: "dead-letter"`,
  `attempts: <maxRetries>`, `last_error`, and the target URL
- **AND** the row originated from `WebhookLog` in OR — not from a separate
  OC-local table

#### Scenario: non-admin is rejected
- **GIVEN** a non-admin authenticated user
- **WHEN** they request `GET /api/federation/broadcasts`
- **THEN** the response is `403` and no broadcast data leaks

## Removed Requirements

None — this change brings live code into conformance with already-declared
requirements rather than altering the requirement set itself.
