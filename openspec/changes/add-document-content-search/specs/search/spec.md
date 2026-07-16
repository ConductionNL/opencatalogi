## MODIFIED Requirements

### Requirement: Search results are a flat envelope with `@self.schema` as the row discriminator (SCH-PFTS-002)

`GET /apps/opencatalogi/api/search` MUST return a single flat result array whose rows MAY mix object types. Each row MUST carry an `@self.schema` field whose value identifies the row's schema slug (`publication`, `document`, …); callers use this field as the discriminator to render row-specific UI. Rows MUST NOT be grouped into per-type sub-arrays in the response envelope.

This shape is consistent with the existing `/publications` envelope's use of `@self` metadata; clients MUST be able to switch rendering on `@self.schema` without inspecting any other field.

**Content vs metadata matches** — When the `_content=true` opt-in is set (see SCH-PFTS-CONTENT-001), a document row MAY have been surfaced because its extracted body text matched the query instead of a metadata field. Such a row MUST be indistinguishable in envelope shape from a document row surfaced by a metadata match. Clients MUST NOT rely on any field to tell the two apart. A document that matches on BOTH surfaces MUST appear exactly once in the response, deduplicated on `@self.id`.

#### Scenario: mixed-type rows are returned in a single flat array

- **GIVEN** a search query matching both publications and documents,
- **WHEN** the response is inspected,
- **THEN** the result rows MUST live in a single flat array,
- **AND** each row MUST carry `@self.schema` set to the row's schema slug,
- **AND** the response MUST NOT contain separate `publications` / `documents` sub-arrays.

#### Scenario: content-matched and metadata-matched document rows share the same shape

- **GIVEN** two documents seeded with `_content=true` enabled: `doc-A` whose title contains the query and `doc-B` whose body text contains the query but title does not,
- **WHEN** the response is inspected,
- **THEN** both rows MUST have `@self.schema = "document"`,
- **AND** both rows MUST carry the same `publication: { id, slug, title }` envelope,
- **AND** no field on either row MUST reveal which surface matched.

#### Scenario: document matching both surfaces appears once

- **GIVEN** a document whose title AND body text both contain the query, and `_content=true`,
- **WHEN** the response is inspected,
- **THEN** the document row MUST appear exactly once in the results array.

## ADDED Requirements

### Requirement: `_content` opt-in flag widens matching to include document body text (SCH-PFTS-CONTENT-001)

`GET /apps/opencatalogi/api/search` MUST accept an optional boolean query parameter `_content`. When absent or false, the endpoint's behaviour MUST be byte-identical to the WOO-506 baseline (metadata + string properties only, no content search). When `_content=true`, the assembler MUST additionally include documents whose OpenRegister-extracted body text matches the query — extracted by OR's `TextExtractionService` pipeline (PDF, DOCX, XLSX, EML, text/*) and indexed in `openregister_chunks`, surfaced via the OR-side `_content_search` flag exposed on `ObjectService::searchObjectsPaginated()` (that flag is the prerequisite dependency; see proposal `depends_on`).

The `_content` flag MUST default to false — no request without an explicit opt-in MUST trigger a content-search fan-out, so the added query cost is opt-in and existing consumers see zero behavioural drift.

#### Scenario: default omits content search

- **GIVEN** `GET /apps/opencatalogi/api/search?_search=<query>` without `_content`,
- **WHEN** the response is compared to the WOO-506 baseline,
- **THEN** the response envelope MUST be byte-identical to the pre-WOO-517 baseline for that query.

#### Scenario: opt-in adds content-matched documents

- **GIVEN** a document seeded with body text containing the phrase `"lorem-ipsum-woo517-marker"` and no metadata field containing that phrase,
- **AND** OR's text-extraction job has completed for that document (chunks visible in `openregister_chunks.text_content`),
- **WHEN** the caller issues `GET /apps/opencatalogi/api/search?_search=lorem-ipsum-woo517-marker&_content=true`,
- **THEN** the response MUST include a row for that document with `@self.schema = "document"` and the embedded `publication: { id, slug, title }` summary.

#### Scenario: opt-in cost is opt-in

- **GIVEN** two identical requests differing only in `_content` (one omitted, one true),
- **WHEN** OR-side query logs are compared,
- **THEN** the `_content=true` request MUST fan out to a chunk-search query,
- **AND** the omitted-flag request MUST NOT fan out to a chunk-search query.

### Requirement: Content-matched results are documents, not chunks (SCH-PFTS-CONTENT-002)

When a body-text match surfaces a document, the endpoint MUST return the **document object** (matching the `document` schema shape defined by `SCH-PFTS-005`), not the raw chunk that produced the match. The assembler MUST map each chunk hit to its owning document via `openregister_chunks.source_type = 'file'` + `source_id`, resolve the document object, and include it in the flat envelope with `@self.schema = "document"` and the embedded `publication` summary defined by `SCH-PFTS-003`. Raw chunk payloads (chunk id, snippet, score) MUST NOT appear in the public response.

Documents whose linked publication MUST NOT appear (SCH-PFTS-003's rule about documents with no valid linked publication) are dropped from the content-search result set as well.

#### Scenario: chunk hit is returned as a document row

- **GIVEN** a chunk in `openregister_chunks` with `source_type = 'file'` and `source_id = 42`, where the file is attached to document object `doc-X` linked to publication `pub-Y`,
- **WHEN** `GET /apps/opencatalogi/api/search?_search=<phrase>&_content=true` matches that chunk,
- **THEN** the response row MUST be document `doc-X` with `@self.schema = "document"`,
- **AND** MUST include `publication: { id: pub-Y.id, slug: pub-Y.slug, title: pub-Y.title }`,
- **AND** MUST NOT include the chunk's raw text snippet, chunk id, or score field.

#### Scenario: chunk-matched document with no linked publication is suppressed

- **GIVEN** a chunk whose owning document has no linked publication,
- **WHEN** the content-search assembler runs,
- **THEN** the document MUST NOT appear in the anonymous response.

### Requirement: Content matches inherit the anonymous visibility filter (SCH-PFTS-CONTENT-003)

Content-matched document rows MUST pass through the identical `isObjectPublic()` post-filter and transitive publication-visibility gate defined by `SCH-PFTS-004`. The gate applies AFTER the content-search fan-out and result mapping, never as a pre-filter on the chunk query. This guarantees the anonymous content-search surface has the same visibility semantics as the anonymous metadata-search surface — no draft or depublished content leaks through either path.

#### Scenario: content-matched depublished document is dropped

- **GIVEN** a document with `depublicatiedatum` in the past,
- **AND** a chunk from that document whose body text matches the query,
- **WHEN** an anonymous `GET /apps/opencatalogi/api/search?_search=<query>&_content=true` runs,
- **THEN** the depublished document MUST NOT appear in the response envelope,
- **AND** the chunk MUST NOT be represented in any other row shape either.

#### Scenario: content match with depublished parent publication is dropped

- **GIVEN** a document whose own `publicatiedatum <= now` but whose linked publication has `depublicatiedatum` in the past,
- **AND** a chunk from that document whose body text matches the query,
- **WHEN** an anonymous `GET /apps/opencatalogi/api/search?_search=<query>&_content=true` runs,
- **THEN** the document MUST NOT appear in the response envelope (transitive visibility per SCH-PFTS-004).
