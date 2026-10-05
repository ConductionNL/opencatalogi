---
kind: code
depends_on: []
---

# Proposal: api-says-what-it-filtered

## Why

An integrator who asks for "all publications" and gets 412 has no way to know whether that is all of them. The public API narrows every list on its own: to the catalogue's schemas and registers, to what an anonymous reader may see, and past archived records. It ignores a scope a caller sends. None of that is said in the response, so a short list reads the same as a complete one.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **13.27** "A list endpoint applies no filter the caller did not ask for, or says in the response that it did". Ours: partial, production. Evidence: "lib/Service/PublicationQueryService.php applies catalogue scope and published-only filters the caller did not ask for, and lib/Service/SearchRangeGuard.php bounds a query; neither is reported back in the response".
- **13.26** "An integrator-only filter exists with no screen behind it, and the product says so". Ours: no. Evidence: "nothing documents an integrator-only filter, because none exists". This row is flagged "deliberately not building" in the plan (reason: "an integrator-only filter with no screen behind it is a GPP quirk the round recorded, not a capability"), and decision D10 leaves it awaiting Ruben's strike or keep. Its requirement is written here and gated.

Read on development at 35999c296. The filters applied without being asked: the configured catalogue scope (`_schemas`, `_registers`), with any caller scope removed by `CallerScope::strip()` (23 call sites); the public read rule of each schema (anonymous evaluation, `evaluateAsAnonymous()`); the RET-006 drop of `status: archived` rows in `PublicationQueryService` (the `$droppedCount`); and `SearchRangeGuard`, which refuses a malformed range by name (REQ-SCF-002) and so applies no hidden bound. The OpenAPI document is `openapi.json`, kept in step with the routes by `tests/Unit/OpenApiParityTest.php` (API-DOC-002).

## What changes

- Every JSON list and search response of the public API carries `appliedFilters`: a list of `{name, value, reason}` for each filter the server applied that the caller did not ask for. The names are fixed: `catalogScope`, `publicOnly`, `archivedExcluded`, `callerScopeIgnored`, and `unlisted` once `publication-lifecycle-on-or` ships it.
- One collector, `AppliedFilters`, is filled where each filter is applied, not reconstructed afterwards, so a filter cannot be applied without being reported.
- `openapi.json` documents `appliedFilters` on every list response, and the parity test checks it.
- Gated on D10: integrator-only parameters are marked `x-integrator-only` in `openapi.json`, and a drift test fails on an unmarked parameter that no screen sends.

## Fail closed

- A filter whose site does not report is a test failure, not a silent omission: a test drives each list route with a fixture that triggers every filter and asserts each name.
- `callerScopeIgnored` names the keys removed, never their values when a value could carry something the caller should not see echoed (the values are the caller's own; they are echoed only as key names).
- When the collector itself fails, the response is still served with `appliedFilters` set to `[{name: "unknown", reason: "not recorded"}]`, never an empty list that reads as "nothing filtered".

## Out of scope

- Removing any of these filters. They are there on purpose; this change makes them visible.
- Non-JSON surfaces (sitemaps, DCAT, OAI-PMH). Their filters are fixed by their standards.

## Dependencies

- None to build. `publication-lifecycle-on-or` (wave 1, planned) adds the `unlisted` filter; whichever lands second adds the `unlisted` entry, and this proposal names it so neither forgets.

## Wave

Wave 1. It needs nothing new.

## Decisions

- D10: 13.26 is flagged and awaits strike or keep. Its requirement (REQ-ASF-003) and tasks (group 4) are gated: the builder skips them unless Ruben has kept 13.26.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 13.27 | A list endpoint applies no filter the caller did not ask for, or says in the response that it did | partial | REQ-ASF-001 and REQ-ASF-002, scenarios "The response says the catalogue was narrowed" and "A caller's scope is ignored and said so" |
| 13.26 | An integrator-only filter exists with no screen behind it, and the product says so | no | gated on D10: REQ-ASF-003, scenario "An unmarked integrator-only parameter fails the build" |
