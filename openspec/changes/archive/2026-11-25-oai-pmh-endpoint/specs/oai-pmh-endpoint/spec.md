# oai-pmh-endpoint (delta)

## ADDED Requirements

### Requirement: OAI-PMH 2.0 repository per DCAT-enabled catalog (OAI-001)
Every catalog whose DCAT feed is enabled MUST also be exposed as an OAI-PMH
2.0 repository at `GET /catalog/{slug}/oai`, implementing the verbs
`Identify`, `ListMetadataFormats`, `ListSets`, `ListIdentifiers`,
`ListRecords` and `GetRecord`, and answering an unknown or missing verb with
the `badVerb` protocol error. The endpoint MUST be public and read-only, and
record visibility MUST derive from the same OR public predicate as the DCAT
feed (DCAT-003); the endpoint MUST NOT implement a second visibility check.

#### Scenario: Identify describes the repository
- GIVEN a DCAT-enabled catalog "vergunningen" with at least one visible
  publication
- WHEN `?verb=Identify` is requested
- THEN the response MUST carry repositoryName, the endpoint's own baseURL,
  protocolVersion `2.0`, the oldest visible publication's datestamp as
  earliestDatestamp, deletedRecord `transient` and granularity
  `YYYY-MM-DDThh:mm:ssZ`

> @e2e exclude Authored in the spec-only re-scope of the
> `dcat-oai-pmh-harvesting` umbrella; task 3.5 of this change adds
> `tests/e2e/spec-coverage/oai-pmh-endpoint.spec.ts` covering Identify and
> replaces this marker with the real reference when the implementation lands.

#### Scenario: An unknown verb is a protocol error, not a crash
- GIVEN the same catalog
- WHEN `?verb=Frobnicate` is requested
- THEN the response MUST be an OAI-PMH `badVerb` error document with HTTP 200

> @e2e exclude Authored in the spec-only re-scope; covered by the task 3.1
> unit verb/argument matrix and folded into the task 3.5 e2e spec at
> implementation time.

### Requirement: oai_dc and dcat metadata prefixes (OAI-002)
The repository MUST serve the `oai_dc` (Dublin Core) and `dcat` metadata
prefixes and MUST answer any other prefix with `cannotDisseminateFormat`.
The `oai_dc` record MUST be mapped from the publication's DCAT dataset node
(DCAT-004 annotation pipeline), so both prefixes present the same underlying
projection, and Dublin Core elements MUST carry `xml:lang` where the source
field declares a language. `oai_datacite` is explicitly out of scope.

#### Scenario: The same publication through both prefixes
- GIVEN a visible publication with title, description, license and one
  attachment
- WHEN its record is fetched with `metadataPrefix=oai_dc` and again with
  `metadataPrefix=dcat`
- THEN the `oai_dc` record MUST carry dc:title/dc:description/dc:rights and a
  dc:format per distribution
- AND the `dcat` record MUST embed the same JSON-LD dataset node the DCAT
  feed serves

> @e2e exclude Authored in the spec-only re-scope; unit-covered field by
> field (task 3.2) and exercised end to end by the task 3.5 e2e spec at
> implementation time.

### Requirement: Resumption-token pagination and selective harvesting (OAI-003)
`ListIdentifiers` and `ListRecords` MUST paginate with resumption tokens at
the DCAT-008 page size. Tokens MUST be stateless (an authenticated encoding
of cursor, set, window and prefix with an expiry) and a tampered or expired
token MUST yield `badResumptionToken`. `from`/`until` MUST window records on
the publication's modified timestamp, and an empty window MUST yield
`noRecordsMatch`.

#### Scenario: A harvester walks the full set page by page
- GIVEN more visible publications than one page holds
- WHEN a harvester follows resumptionToken until the token element is empty
- THEN the union of pages MUST contain every visible publication exactly once
  in a stable order

> @e2e exclude Authored in the spec-only re-scope; token round-trip and page
> walk are task 3.3 unit coverage plus the task 3.5 e2e page-walk when the
> implementation lands.

#### Scenario: Incremental harvest picks up only the changed record
- GIVEN a completed harvest and one publication modified afterwards
- WHEN `ListRecords` is requested with `from` set to the previous harvest
  time
- THEN exactly the modified publication MUST be returned

> @e2e exclude Authored in the spec-only re-scope; covered by task 3.1 unit
> windowing tests, e2e folded into task 3.5 at implementation time.

### Requirement: Tombstones for depublished records (OAI-004)
A publication that was publicly visible and has been depublished MUST appear
inside a `from`/`until` window that covers its depublication as a
`<header status="deleted">` tombstone, and `Identify` MUST declare
`deletedRecord: transient`. The repository MUST NOT claim persistent
deletion tracking.

#### Scenario: A depublished record surfaces as a tombstone
- GIVEN a publication depublished yesterday
- WHEN `ListIdentifiers` is requested with a `from` of two days ago
- THEN its header MUST carry `status="deleted"` and no metadata

> @e2e exclude Authored in the spec-only re-scope; tombstone emission is task
> 3.4 unit coverage, e2e folded into task 3.5 at implementation time.

### Requirement: Stable OAI identifiers derived from the DCAT IRI (OAI-005)
Record identifiers MUST use the scheme `oai:{host}:{catalogSlug}/{uuid}`
derived from the DCAT dataset IRI, MUST be stable across renames and
republications of the same object, and MUST never expose a database row id.
`GetRecord` for an identifier outside this scheme or naming an invisible
object MUST yield `idDoesNotExist`.

#### Scenario: The identifier survives a title change
- GIVEN a harvested record's OAI identifier
- WHEN the publication's title changes and the record is fetched again by
  that identifier
- THEN the same identifier MUST resolve to the updated record

> @e2e exclude Authored in the spec-only re-scope; identifier stability is
> asserted by task 3.1 unit coverage and the task 3.5 e2e GetRecord check at
> implementation time.

## REMOVED Requirements

Amendment 2026-10-05, decision D10: row 9.10 (OAI-PMH) is struck.

### Requirement: OAI-PMH 2.0 repository per DCAT-enabled catalog (OAI-001)
**Reason:** D10 struck OAI-PMH. **Migration:** none; nothing was built.

### Requirement: oai_dc and dcat metadata prefixes (OAI-002)
**Reason:** D10 struck OAI-PMH. The DCAT dataset node is kept in OAI-006. **Migration:** none.

### Requirement: Resumption-token pagination and selective harvesting (OAI-003)
**Reason:** D10 struck OAI-PMH. The changed-since window is kept in OAI-006. **Migration:** none.

### Requirement: Tombstones for depublished records (OAI-004)
**Reason:** D10 struck OAI-PMH. Reporting withdrawn records is kept in OAI-007. **Migration:** none.

### Requirement: Stable OAI identifiers derived from the DCAT IRI (OAI-005)
**Reason:** D10 struck OAI-PMH. The DCAT dataset IRI stays the stable identifier. **Migration:** none.

## ADDED Requirements

Amendment 2026-10-05, Woo capability programme, row 8.12.

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
