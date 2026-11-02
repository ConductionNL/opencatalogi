# Tasks

## 1. Resolve by slug

- [x] 1.1 `PublicationsController::show()`: after the unchanged UUID lookup finds nothing, look the identifier up as `@self.slug` across every register × schema of the catalogue, collect all matches; one → render as the UUID hit does; none → existing 404
- [x] 1.2 More than one match → existing 404 body + warning naming catalogue, slug and the matching UUIDs (no UUIDs in the response)

Acceptance criteria:
- publications#PUB-002 and PUB-019 scenarios pass on the NC 35 rig.
- A UUID URL answers byte-identically to before.

## 2. UI route

- [x] 2.1 Verify the admin detail route opens a publication by slug — realised by OpenRegister (openregister#4774), no OpenCatalogi code: the page loads the object through OpenRegister's object API, whose slug resolve also serves the history, attachments and relation widgets (verified on the NC 35 rig, 2026-10-26; design D4)

## 3. Tests and verification

- [x] 3.1 Unit tests (`tests/Unit/Controller/PublicationsControllerSlugTest.php`), one per scenario of PUB-002, PUB-019 and PUB-020
- [x] 3.2 Rig check on NC 35 with openregister#4037: slug 200, UUID 200, ambiguous slug 404 + warning; record the result in the issue plan
- [x] 3.3 `@spec` tags on the changed methods; `openspec validate publication-resolves-by-slug --strict`

Acceptance criteria:
- Every test asserts the returned UUID, not only the status.
