# oai-pmh-endpoint Specification

## Purpose

A re-user asks a catalogue which public publications changed, and which were withdrawn, since a moment. Decision D10 (2026-10-05) struck the OAI-PMH protocol itself (OAI-001 to OAI-005 were never built); what remains is the changed-since read of row 8.12.

## Requirements

### Requirement: A re-user asks what changed since a moment (OAI-006)

`GET /api/{catalogSlug}/changes` SHALL be a public, read-only route (GET and its CORS preflight only) that SHALL require `since` (ISO 8601; a malformed value answers 400 naming `since`) and SHALL answer `{changed: [...], withdrawn: [...], since, until, next}`. `changed` SHALL hold, for every publication in the catalogue that the anonymous public read returns and whose `@self.updated` is at or after `since`, the DCAT dataset node `DcatMappingService` renders for it, ordered by `@self.updated` then id. `until` SHALL be the server moment the read was taken, to be used as the next `since`. Pages SHALL hold at most 100 entries, and `next` SHALL be a cursor URL while more remain. A draft, a scheduled or a non-public publication SHALL never appear in `changed`.

#### Scenario: A re-user asks what changed since yesterday
<!-- @e2e exclude Public machine endpoint; proven by ChangesEndpointTest::testOnlyPublicRecordsChangedSinceAreListed, which fails on today's code because the route does not exist. -->

- **GIVEN** a catalogue with one public publication edited today, one public publication unchanged for a month, and one draft edited today
- **WHEN** a re-user asks `GET /api/{catalogSlug}/changes?since=<yesterday>`
- **THEN** `changed` holds exactly the edited public publication as a DCAT dataset node
- **AND** `until` is the moment of the read

#### Scenario: A malformed moment
<!-- @e2e exclude Input validation; proven by ChangesEndpointTest::testAMalformedSinceIs400. -->

- **WHEN** a re-user asks with `since=gisteren`
- **THEN** the answer is 400 naming `since`

### Requirement: A withdrawn record is reported, not shown (OAI-007)

`withdrawn` SHALL hold `{id, withdrawnAt}` for each publication of the catalogue that was withdrawn or archived at or after `since` and was once public: it has a `depublication` record, or a stored `firstReleasedAt` once `publication-lifecycle-on-or` provides it. It SHALL carry no title, summary, file or other field. A publication that was never public SHALL never appear.

#### Scenario: A withdrawn record is reported, not shown
<!-- @e2e exclude Fail-closed contract; proven by ChangesEndpointTest::testAWithdrawnRecordIsListedWithIdAndDateOnly and ChangesEndpointTest::testANeverPublicDraftIsNotListed. -->

- **GIVEN** a publication that was public last week and was withdrawn today, and a draft deleted today
- **WHEN** a re-user asks what changed since yesterday
- **THEN** `withdrawn` holds the first with its id and today's date only
- **AND** nothing names the draft
