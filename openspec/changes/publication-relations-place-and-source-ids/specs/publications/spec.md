---
status: proposed
---

# Publication relations, place and source identifiers

## ADDED Requirements

### Requirement: A publication names its relation to another publication (REQ-PRS-001)

The publication schema SHALL declare `x-openregister-relation-types` with these entries, labels as i18n keys:

| key | label (nl) | inverse label (nl) | symmetric |
|---|---|---|---|
| `replaces` | vervangt | wordt vervangen door | no |
| `amends` | wijzigt | wordt gewijzigd door | no |
| `implements` | geeft uitvoering aan | wordt uitgevoerd door | no |
| `relatedTo` | hoort bij | | yes |

It SHALL declare one array property per key (`replaces`, `amends`, `implements`, `relatedTo`), each item a `$ref` to `#/components/schemas/publication` with `x-openregister-relation: {type: <key>}`. OpenRegister SHALL refuse an item that is not a publication. A publication SHALL NOT relate to itself; a pre-save check SHALL refuse it naming the property.

#### Scenario: Both sides read the relation

- **GIVEN** publication B "Parkeerbesluit 2027" whose `replaces` holds publication A "Parkeerbesluit 2024", both public
- **WHEN** a reader opens B's public detail, and then A's
- **THEN** B's response carries a relation to A labelled "vervangt"
- **AND** A's response carries a relation to B labelled "wordt vervangen door"
- **AND** the officer page of A shows "wordt vervangen door Parkeerbesluit 2027" in its Related panel

#### Scenario: A publication cannot replace itself
<!-- @e2e exclude Pre-save refusal; proven by PublicationRelationCheckTest::testASelfRelationIsRefused, built on the real ObjectUpdatingEvent. -->

- **GIVEN** a publication
- **WHEN** it is saved with its own id in `replaces`
- **THEN** the save is refused naming `replaces`

### Requirement: The public response carries the relations a reader may see (REQ-PRS-002)

`PublicationsController::show()` SHALL add `relations: [{type, label, direction, id, title, url}]` built from OpenRegister's `uses` and `used` rows for the four relation properties, with the label for the direction read. It SHALL include a related publication only when the public read path returns it. `GET /api/{catalogSlug}/{id}/uses` and `/used` SHALL carry the label per row, as OpenRegister answers it.

#### Scenario: A relation to a non-public publication is not shown
<!-- @e2e exclude Fail-closed contract on the public API; proven by PublicationRelationsResponseTest::testARelationToANonPublicPublicationIsLeftOut, which fails on today's code because the response carries no relations. -->

- **GIVEN** a public publication whose `amends` holds a draft
- **WHEN** an anonymous reader fetches the public publication
- **THEN** its `relations` is empty and nothing in the response names the draft

### Requirement: A publication carries its source identifiers, searchable (REQ-PRS-003)

The publication schema SHALL carry `sourceIdentifiers`: an array of `{system (string, required), identifier (string, required), public (boolean, default false)}`. A pre-save listener SHALL mirror a non-empty `caseReference` into `sourceIdentifiers` as `{system: "case", identifier: <caseReference>, public: false}` when no entry with that system and identifier exists, and SHALL never remove an entry. A repair step SHALL do the same once for every stored publication. The internal list endpoints and `GET /api/publications/ready` SHALL accept `sourceIdentifier=<system>:<identifier>` (exact match on both). The public list and search endpoints SHALL accept the same filter and SHALL match only entries with `public: true`, and the public response SHALL carry only those entries.

#### Scenario: A source system finds its record by its own number
<!-- @e2e exclude API contract for a calling system; proven by SourceIdentifierFilterTest::testTheInternalListFiltersOnSystemAndIdentifier, which fails on today's code because the property and the filter do not exist. -->

- **GIVEN** a publication with `sourceIdentifiers` `[{system: "decos", identifier: "Z-2026-0412"}]`
- **WHEN** an authenticated caller lists publications with `sourceIdentifier=decos:Z-2026-0412`
- **THEN** the answer holds exactly that publication

#### Scenario: An internal number stays internal
<!-- @e2e exclude Fail-closed contract on the public API; proven by SourceIdentifierFilterTest::testAPrivateIdentifierIsNeitherReturnedNorMatchedPublicly. -->

- **GIVEN** a public publication with `sourceIdentifiers` `[{system: "decos", identifier: "Z-2026-0412", public: false}, {system: "raad", identifier: "RB-2026-17", public: true}]`
- **WHEN** an anonymous reader fetches it, and searches with `sourceIdentifier=decos:Z-2026-0412`
- **THEN** the response carries only the `raad` entry
- **AND** the search finds nothing

#### Scenario: A dossiq handoff keeps working
<!-- @e2e exclude Cross-app save contract; proven by SourceIdentifierMirrorTest::testTheDossiqPayloadIsMirrored, which saves the payload dossiq's WooPublicationService::buildPayload() produces today. -->

- **GIVEN** dossiq's handoff payload with `caseReference` set to a case uuid
- **WHEN** it is saved as a publication
- **THEN** `sourceIdentifiers` holds `{system: "case", identifier: <that uuid>, public: false}`

### Requirement: A publication carries a geographic location (REQ-PRS-004)

Gated on decision D10: build only once Ruben keeps row 2.16.

The publication schema SHALL carry `geo`, a GeoJSON geometry validated on save by OpenRegister's geometry support (REQ-GEOMAP-001 of `openregister/geometry-on-a-map`). `DcatMappingService` SHALL emit it as `dct:spatial` with a `locn:geometry` literal, and the public response SHALL carry it as `geo`. The publication detail manifest SHALL place the OpenRegister maps leaf widget bound to `publication.geo` (PUB-MAP-001).

#### Scenario: A publication with a place

- **GIVEN** a public publication with `geo` a point in Utrecht
- **WHEN** a reader opens it, and the DCAT feed is requested
- **THEN** the detail page shows the point on a map
- **AND** the dataset in the feed carries `dct:spatial` with that point
