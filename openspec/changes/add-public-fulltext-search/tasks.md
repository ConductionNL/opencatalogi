# Tasks: add-public-fulltext-search

This change is `kind: mixed` (per ADR-032). Tasks are ordered so the schema declaration lands before the code that consumes it, mirroring a config → code chain even though it ships as one change.

- [ ] Freeze the delta spec under `openspec/changes/add-public-fulltext-search/specs/search/spec.md` (ADDED requirements SCH-PFTS-001 … SCH-PFTS-006); confirm `openspec validate add-public-fulltext-search` is green
  - Spec ref: specs/search/spec.md (this change)
  - Acceptance: openspec validator reports "is valid"; SCH-OR-001/SCH-OR-002 untouched
- [ ] Add the `document` schema to `lib/Settings/publication_register.json` as a bundled schema (alongside `publication`, `catalog`, …)
  - Spec ref: SCH-PFTS-005
  - Acceptance: schema present under `components.schemas.document`; listed in the publication register's `schemas` array; `searchable: true`; `authorization.read` mirrors `publication`'s anonymous projection
- [ ] Wire the `document` schema into `configuration.schemas` so the magic mapper allocates a dedicated table on first install
  - Spec ref: SCH-PFTS-005
  - Acceptance: `configuration.schemas.document` carries `magicMapping: true` and `autoCreateTable: true`
- [ ] Generate seed data: at least 2 document objects (municipality scenario + consultancy scenario) linked to existing seed publications
  - Spec ref: ADR-001 (Seed Data) + design.md "Seed Data"
  - Acceptance: seed rows reachable on a fresh install; each row carries `@self.schema = "document"` and an embedded `publication: {id, slug, titel}`
- [ ] Promote `lib/Controller/SearchController::index()` to public — remove the anonymous-401 guard, annotate with `#[PublicPage]` + `#[NoCSRFRequired]`
  - Spec ref: SCH-PFTS-001
  - Acceptance: anonymous GET returns HTTP 200 with a result envelope; no path to HTTP 401 on the endpoint
- [ ] Register the public route in `appinfo/routes.php` (or confirm the existing route is reused) so `GET /apps/opencatalogi/api/search` resolves to `SearchController::index`
  - Spec ref: SCH-PFTS-001
  - Acceptance: route present; `gate-route-reachability` and `gate-route-auth` both green
- [ ] Extend the search assembly (a helper in `PublicationQueryService` or a sibling service) to call OR's `zoeken-filteren` across both `publication` and `document` schemas and merge candidate rows into one flat array
  - Spec ref: SCH-PFTS-002, SCH-PFTS-006
  - Acceptance: response envelope is a single array; each row carries `@self.schema`; no separate `publications`/`documents` sub-arrays
- [ ] Embed `publication: {id, slug, titel}` on each document row during assembly
  - Spec ref: SCH-PFTS-003
  - Acceptance: every document row in the response carries the embedded publication summary; rows whose linked publication is missing are dropped
- [ ] Apply `isObjectPublic()` to anonymous results AFTER scoring/merge (post-filter, never folded into the OR query)
  - Spec ref: SCH-PFTS-004
  - Acceptance: PHPUnit test demonstrates that a candidate row excluded by visibility was present pre-filter and absent post-filter; ordering invariant documented in the helper's docblock
- [ ] Enforce transitive visibility on documents: drop document rows whose linked publication fails `isObjectPublic()`
  - Spec ref: SCH-PFTS-004
  - Acceptance: PHPUnit test with a document whose linked publication is depublished; document MUST NOT surface for anonymous callers
- [ ] Add Newman / PHPUnit tests proving anonymous reachability + 200 status on `/apps/opencatalogi/api/search`
  - Spec ref: SCH-PFTS-001
  - Acceptance: tests run in CI; cover the no-auth-header and depublished-row cases
- [ ] Add regression coverage proving `GET /publications` is unchanged (existing assertions still pass; no new fields, no removed fields, no admission-rule drift)
  - Spec ref: SCH-PFTS-001 (negative)
  - Acceptance: existing publications endpoint tests run unmodified; no diff in their fixtures
- [ ] Document Path A vs Path B in the controller / service docblock (linking to design.md's dual-path section) so future implementers know which extraction surface to wire when Ruben's answer arrives
  - Spec ref: design.md "Dual-path design"
  - Acceptance: docblock comment references the proposal's "Pending decisions" block; no extraction code added in this change
- [ ] Update `openspec/specs/search/spec.md` (the canonical capability) to list this change under an `**OpenSpec changes**` block with status `in-progress`
  - Acceptance: spec carries a canonical `**Status**: in-progress / **Scope**: opencatalogi / **OpenSpec changes**:` block listing `add-public-fulltext-search`
- [ ] Run the Hydra mechanical gates locally (`scripts/run-hydra-gates.sh --scope-to-diff`) and resolve every BLOCKING issue before opening the PR
  - Acceptance: all 45 gates either PASS or carry a documented exclusion
- [ ] Open the PR; confirm reviewer + security-reviewer pass; address inline review nits without scope creep
  - Acceptance: PR review verdict is APPROVE on both lanes
- [ ] Confirm Ruben's answer received + (if needed) open a follow-up OpenSpec change before archiving this one
  - Acceptance: Ruben's decision on document content indexing is recorded in WOO-506; if "no/later", a B3 change (e.g. `add-document-content-search`) is created and linked from this change's proposal before archive

## Quality checklist

- spec validates (`openspec validate add-public-fulltext-search`)
- `gate-route-auth`, `gate-route-reachability`, `gate-spdx`, `gate-stub-scan`, `gate-forbidden-patterns` all green
- `gate-no-admin-idor` passes against the now-public `SearchController::index` (controller MUST guard via the visibility filter, not via admin-required)
- regression suite against `/publications` passes unmodified
- seed data installs on a fresh container (`clean-env` recipe)
- Ruben's pending-decision answer is recorded before `openspec archive` runs
