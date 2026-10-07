---
kind: code
depends_on: []
---

# Proposal: instance-staging-mode

## Summary

An instance can run in staging mode, where every national hand-over (Woo-index, PLOOI, federation partners) records a dry run instead of sending, so the Woo flow can be rehearsed end to end.

- Rows: 13.5.
- Wave: 1.
- Depends on: nothing to build. Uses `nextcloud-vue/environment-banner` (https://github.com/ConductionNL/nextcloud-vue/issues/1320) when present; without it an OpenCatalogi notice shows the same text. Without integriq the dry run is recorded before the reachability check.
- Decision: none of D1 to D13.
- Build rules: openspec/woo-build-rules.md

## Why

An officer learning the Woo flow, or an organisation testing a new catalogue, needs to run it end to end: publish, see the sitemap, run the readiness check, hand over to the national index. On a production-like instance today every one of those steps is real. A mistake reaches the Woo-index, PLOOI or a federation partner, and it cannot be taken back quietly.

Row, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **13.5** "A staging mode lets an officer rehearse without publishing". Ours: partial, production. Evidence: "#catalog.status carries development, beta, stable and obsolete, and a catalogue without hasWooSitemap publishes no sitemap, so an officer can rehearse in a catalogue nobody harvests. There is no instance level staging mode".

Read on development at 35999c296. What leaves the instance from OpenCatalogi: `NationalIndexService::deliver()` (through integriq's publication gateway event), `NationalIndexService::deliverToPlooi()` and `PlooiDeliveryService::deliver()` (PLOOI), `WooRegistrationService::request()` (the Woo-index registration through the gateway), `BroadcastService::sendBroadcastRequest()` (a direct Guzzle POST to federation directories) and `DirectoryService::syncDirectory()` (which fetches and can announce). What the public reads: the public API, search, sitemaps and the sitemap index, DCAT, robots.txt (`RobotsController::index()`), the federation endpoints.

## What changes

- One app setting, `instance_mode`: `production` (default) or `staging`, set by an administrator in the OpenCatalogi admin settings with a confirmation.
- Every outbound channel goes through one gate, `OutboundGate::allow(string $channel): bool`. In staging it answers no. The channel then records a dry run instead of sending: the payload it would have sent, the channel, the moment and the user, kept in a `dryRunDelivery` record the officer can open. The flow continues as if the hand-over succeeded, marked as a dry run, so the officer sees every step.
- In staging the public surface answers only to signed-in users. An anonymous request to a public route answers 503 with `X-Robots-Tag: noindex` and a short body saying the instance is in staging. robots.txt disallows everything. The sitemap index lists nothing for anonymous callers.
- Every OpenCatalogi page shows a staging banner. It uses `nextcloud-vue`'s environment banner (`nextcloud-vue/environment-banner`) when the installed version has it, and an OpenCatalogi notice otherwise.
- The admin settings list what can still leave the instance outside OpenCatalogi: OpenRegister webhooks and integriq synchronisations that are enabled. They are named, not stopped.

## Fail closed

- The gate reads `instance_mode` on every call. When the value cannot be read, the gate answers no and the channel records a dry run with the reason. Sending is the unsafe side.
- A dry run is never recorded as a delivery. `plooiStatus`, national acknowledgements and the registration answer stay empty, so turning staging off does not leave records that claim they reached a national channel.
- Switching from staging to production does not replay the dry runs. They are kept for review and must be published again on purpose.
- The public 503 applies to every public route through one middleware, not per controller, so a new public route is covered by default. A test lists every `#[PublicPage]` route and asserts it.

## Out of scope

- Stopping OpenRegister webhooks or integriq synchronisations. They belong to those apps; this change names them so the officer knows.
- A separate staging database or copy of production data.
- Per-catalogue staging. `catalog.status` keeps its meaning.

## Dependencies

- None to build. Uses the environment banner from `nextcloud-vue/environment-banner` (nextcloud-vue, planned in this programme, wave 1) when present; without it, an OpenCatalogi notice shows the same text.
- integriq absent: the national channels are already unreachable without integriq (`IndexUnreachableException`); in staging they record a dry run before that check, so a rehearsal works without integriq.

## Wave

Wave 1. It needs nothing new.

## Decisions

None of D1 to D13 is implemented here.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 13.5 | A staging mode lets an officer rehearse without publishing | partial | REQ-STG-001 to REQ-STG-003, scenario "An officer rehearses the whole flow and nothing leaves" |
