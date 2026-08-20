# Test Plan: add-document-content-search

Maps every scenario in the spec deltas (`specs/search/spec.md`) to a concrete test case, so implementation done-ness is unambiguous.

## Coverage matrix

| Spec req | Scenario | Test case | Test type | File |
|---|---|---|---|---|
| SCH-PFTS-002 (MODIFIED) | mixed-type rows are returned in a single flat array | `testSearchEnvelopeIsFlatSingleArray` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-002 (MODIFIED) | content-matched and metadata-matched document rows share the same shape | `testContentAndMetadataDocumentRowsAreShapeIdentical` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-002 (MODIFIED) | document matching both surfaces appears once | `testDocumentMatchingBothSurfacesIsDeduplicated` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-CONTENT-001 | default omits content search | `testContentFlagDefaultIsFalse` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-CONTENT-001 | opt-in adds content-matched documents | `testContentFlagTrueSurfacesBodyTextMatches` | E2E | `tests/e2e/content-search-endpoint.spec.ts` |
| SCH-PFTS-CONTENT-001 | opt-in cost is opt-in | `testContentFlagTrueTriggersChunkSearchFanOut` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-CONTENT-002 | chunk hit is returned as a document row | `testChunkHitReturnedAsMappedDocument` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-CONTENT-002 | chunk-matched document with no linked publication is suppressed | `testChunkMatchedDocumentWithoutPublicationIsSuppressed` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-CONTENT-003 | content-matched depublished document is dropped | `testContentMatchOnDepublishedDocumentIsDropped` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |
| SCH-PFTS-CONTENT-003 | content match with depublished parent publication is dropped | `testContentMatchWithDepublishedParentIsDropped` | PHPUnit | `tests/Unit/Service/PublicationQueryServiceTest.php` |

## Test setup notes

- **Chunk store fake** — for PHPUnit, stub the OR-side `ObjectService::searchObjectsPaginated()` so the `_content_search`-flag branch returns a deterministic chunk-hit list mapped to seeded document rows. No real OR container needed for unit tests.
- **E2E seed corpus** — `testContentFlagTrueSurfacesBodyTextMatches` needs an actual extracted chunk. Two options: (a) seed a small PDF with a distinctive phrase (`lorem-ipsum-woo517-marker`) and poll `openregister_chunks` until the extraction job populates it; (b) inject a chunk row directly for the E2E test. Both acceptable — decide at impl time based on WOO-531 progress (seed-data script with real PDF/DOCX bodies).
- **Dedup fixture** — for `testDocumentMatchingBothSurfacesIsDeduplicated` seed one document whose title contains `"lorem-ipsum-woo517-marker"` AND whose body chunk contains the same phrase, then assert `count(results) === 1` and the document uuid appears exactly once.
- **Envelope byte-identity check (SCH-PFTS-CONTENT-001 "default omits")** — snapshot the WOO-506 response for a fixed query, then run the same query on the WOO-517 branch without `_content`; assert byte-identical envelopes.
- **Visibility fixtures** — reuse WOO-506's `isObjectPublic` fixtures (already covers depublished doc, depublished linked pub); add a chunk-hit variant for each so the visibility test set stays symmetrical between metadata-match and content-match paths.

## Skipped / deferred

- **MariaDB content-search ranking** — no test on this branch. Chunk index is Postgres-only; on MariaDB the fallback is unranked `LIKE` and equal-quality ranking is a deferred follow-up (see proposal.md "Deliberately deferred"). MariaDB E2E only asserts `HTTP 200` + non-empty results for a content-match query, no ranking order.
- **Snippet fields** — no test. Feature is out of scope; if added later, snippet tests land with that change.

## Definition of done for the test suite

- All 10 rows in the coverage matrix are green in CI.
- E2E test seeds AND cleans up its own fixture data (no state leaks between runs).
- No unmapped scenarios remain in `specs/search/spec.md` — every `#### Scenario:` under an added or modified requirement has a row in the matrix.
