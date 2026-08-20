# Tasks: opencatalogi-delegate-broadcast-to-or-webhooks

## Implementation Tasks

### Task 1: Introduce BroadcastResult value object + enqueueBroadcast adapter
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-broadcast-delegates-to-or-webhooks-fed-or-001`
- **files**: `lib/Service/BroadcastService.php`, `lib/Service/Broadcast/BroadcastResult.php`, `tests/Unit/Service/BroadcastServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a call to `enqueueBroadcast($url, $directoryUrl)` WHEN OR is installed THEN `BroadcastResult` with status `enqueued` is returned
  - GIVEN a call to `enqueueBroadcast($url, $directoryUrl)` WHEN OR is absent THEN a deprecation warning is logged and the legacy sync path is used (transition only)
  - GIVEN the new adapter THEN the existing `broadcast()` public method's `array<string,bool>` return remains usable for legacy callers
- [ ] Implement
- [ ] Test

### Task 2: Wire enqueueBroadcast to OR's WebhookService::triggerWebhookForEvent
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-broadcast-delegates-to-or-webhooks-fed-or-001`
- **files**: `lib/Service/BroadcastService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `enqueueBroadcast($url, $directoryUrl)` is called WHEN OR is installed THEN `WebhookService::triggerWebhookForEvent('opencatalogi.federation.broadcast', payload, $url)` is called exactly once
  - GIVEN the call THEN payload contains `{directory, timestamp, source}` matching today's POST body
  - GIVEN the call THEN the `User-Agent` is forwarded as `OpenCatalogi-Broadcast/<version>` (preserved from legacy path)
- [ ] Implement
- [ ] Test

### Task 3: Lazy-create the shared "opencatalogi.federation.broadcast" Webhook row
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-broadcast-delegates-to-or-webhooks-fed-or-001`
- **files**: `lib/Service/BroadcastService.php`
- **acceptance_criteria**:
  - GIVEN no Webhook row exists for event `opencatalogi.federation.broadcast` WHEN `enqueueBroadcast` is first called THEN one is created with the configured retry policy and persisted
  - GIVEN the row already exists THEN no duplicate is created
  - GIVEN concurrent first-calls THEN row creation is idempotent (catch unique constraint, re-query)
- [ ] Implement
- [ ] Test

### Task 4: Retain pre-flight SSRF guard + allowlist before delegation
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-broadcast-pre-flight-ssrf-guard-fed-or-001`
- **files**: `lib/Service/BroadcastService.php`
- **acceptance_criteria**:
  - GIVEN a target URL pointing at metadata IP `169.254.169.254` WHEN `enqueueBroadcast` is called THEN the SSRF guard throws before any OR call
  - GIVEN an http:// URL (not https + not allowlisted) WHEN `enqueueBroadcast` is called THEN the SSRF guard rejects it
  - GIVEN an allowlisted dev host (e.g. `nc-fed-2` in compose) WHEN `enqueueBroadcast` is called THEN it passes pre-flight even on http://
- [ ] Implement
- [ ] Test

### Task 5: Remove legacy sync retry loop + app-local config keys
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-no-app-local-retry-maths-fed-or-001`
- **files**: `lib/Service/BroadcastService.php`, release notes, docs
- **acceptance_criteria**:
  - GIVEN the new code THEN `sendBroadcastRequest`, `getMaxRetries`, `getRequestTimeout`, `DEFAULT_MAX_RETRIES`, `MAX_RETRY_WALL_SECONDS`, `CONFIG_MAX_RETRIES`, `CONFIG_REQUEST_TIMEOUT` are removed
  - GIVEN a fresh install THEN no `occ config:app:get opencatalogi broadcast_max_retries` reference appears in active code
  - GIVEN the upgrade THEN release notes list the removed keys and point operators to OR's webhook retry policy
- [ ] Implement
- [ ] Test

### Task 6: Expose admin status endpoint `GET /api/federation/broadcasts`
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-dead-letter-visibility-fed-or-002`
- **files**: `lib/Controller/FederationController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin WHEN `GET /api/federation/broadcasts` THEN recent WebhookLog rows filtered by event `opencatalogi.federation.broadcast` are returned with `status, attempts, next_retry_at, last_error`
  - GIVEN a non-admin THEN the endpoint is rejected
  - GIVEN a dead-letter row THEN it appears with `status: "dead-letter"` and is sorted first
- [ ] Implement
- [ ] Test

### Task 7: Vue admin panel — federation broadcast status surface
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-dead-letter-visibility-fed-or-002`
- **files**: `src/views/settings/FederationBroadcastsView.vue`, `src/router.js`
- **acceptance_criteria**:
  - GIVEN an admin opens federation settings THEN a "Broadcasts" tab shows the rows from `/api/federation/broadcasts`
  - GIVEN any dead-letter row THEN it is visually distinct and shows the last error
  - GIVEN a delivered row THEN it shows the delivery timestamp
- [ ] Implement
- [ ] Test

### Task 8: Newman Phase 11 — dead-letter integration test
- **spec_ref**: `openspec/changes/opencatalogi-delegate-broadcast-to-or-webhooks/specs/federation/spec.md#requirement-dead-letter-visibility-fed-or-002`
- **files**: `tests/federation/federation-tests.postman_collection.json`, `tests/federation/run-federation-tests.sh`
- **acceptance_criteria**:
  - GIVEN nc-fed-2 listings include an unreachable peer WHEN nc-fed-1 broadcasts THEN polling `/api/federation/broadcasts` eventually yields a `dead-letter` row for that URL
  - GIVEN nc-fed-2 listings include a reachable peer WHEN nc-fed-1 broadcasts THEN polling yields `delivered` within OR's retry window
  - GIVEN the new phase THEN the full federation suite still runs ≤ 6 minutes locally
- [ ] Implement
- [ ] Test

### Task 9: Update federation spec to mark FED-OR-001/002 implemented
- **spec_ref**: `openspec/specs/federation/spec.md` (move status markers, update migration table)
- **files**: `openspec/specs/federation/spec.md`
- **acceptance_criteria**:
  - GIVEN this change merges THEN FED-OR-001 and FED-OR-002 are marked Implemented in `openspec/specs/federation/spec.md`
  - GIVEN the spec migration table THEN the "Backoff maths removed" row is updated to reflect the actual removal
  - GIVEN the spec THEN there is a link back to this openspec change folder for archaeology
- [ ] Implement
- [ ] Test
