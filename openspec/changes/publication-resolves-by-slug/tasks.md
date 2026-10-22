# Tasks

## 1. Resolve by slug

- [ ] 1.1 `PublicationsController::show()`: after the unchanged UUID lookup finds nothing, look the identifier up as `@self.slug` across every register × schema of the catalogue (`_limit: 2` each), collect all matches; one → render as the UUID hit does; none → existing 404
- [ ] 1.2 More than one match → existing 404 body + warning naming catalogue, slug and the matching UUIDs (no UUIDs in the response)

Acceptance criteria:
- publications#PUB-002 and PUB-019 scenarios pass on the NC 35 rig.
- A UUID URL answers byte-identically to before.

## 2. UI route

- [ ] 2.1 Verify `#/publications/{catalogSlug}/{slug}` opens the publication through the same endpoint; change nothing unless it fails

## 3. Tests and verification

- [ ] 3.1 Unit tests (`tests/Unit/Controller/PublicationsControllerSlugTest.php`), one per scenario of PUB-002, PUB-019 and PUB-020
- [ ] 3.2 Rig check on NC 35 with openregister#4037: slug 200, UUID 200, ambiguous slug 404 + warning; record the result in the issue plan
- [ ] 3.3 `@spec` tags on the changed methods; `openspec validate publication-resolves-by-slug --strict`

Acceptance criteria:
- Every test asserts the returned UUID, not only the status.
