# Tasks: add-document-content-search

This change is `kind: mixed` (per ADR-032). Ships the OpenCatalogi-side wiring only — depends on the OR-side prerequisite (`_content_search` flag on `ObjectService::searchObjectsPaginated()`) landing first.

- [ ] Freeze the delta spec under `openspec/changes/add-document-content-search/specs/search/spec.md` (MODIFIED SCH-PFTS-002 + ADDED SCH-PFTS-CONTENT-001 / -002 / -003); confirm `openspec validate add-document-content-search` is green
  - Spec ref: specs/search/spec.md (this change)
  - Acceptance: openspec validator reports "is valid"; SCH-PFTS-001 / -003 / -004 / -005 / -006 / -007 untouched
- [ ] Verify OR-side prerequisite has shipped in `Conduction/openregister:development`: `ObjectService::searchObjectsPaginated()` accepts a `_content_search` (or agreed final name) flag and routes to `ChunkMapper::searchByKeyword()` when set; document the exact flag name + return shape in this task before impl starts
  - Spec ref: (external) openregister/openspec/changes/expose-content-search-in-object-service/
  - Acceptance: OR release notes or merge commit link recorded here; smoke curl against a seeded OR install returns chunk-matched document rows
- [ ] Extend `PublicationQueryService::assemblePublicSearchResults()` to accept a `_content` boolean flag from `$queryParams` (default false) and forward the corresponding OR flag when true
  - Spec ref: SCH-PFTS-CONTENT-001
  - Acceptance: PHPUnit test with `_content=false` (default) proves current behaviour is byte-identical to WOO-506; separate test with `_content=true` proves the OR flag is set on the delegated query
- [ ] When `_content=true`, execute two searches under the same query scope and deduplicate on `@self.id`: metadata-search (existing WOO-506 path) UNION content-search (chunks routed via the OR flag). Preserve the WOO-506 flat envelope; each document row is indistinguishable in shape whether it matched on metadata or content
  - Spec ref: SCH-PFTS-CONTENT-002, MODIFIED SCH-PFTS-002
  - Acceptance: a document that matches BOTH via title AND body text appears exactly once in the response; a document that matches ONLY via body text is present when `_content=true` and absent when `_content=false`
- [ ] Apply the existing `isObjectPublic()` filter + transitive publication-visibility gate (SCH-PFTS-004) to content-matched document rows before returning
  - Spec ref: SCH-PFTS-CONTENT-003
  - Acceptance: PHPUnit test proves a body-text match on a depublished document (`depublicatiedatum` in the past) is dropped from the anonymous response; separate test proves a body-text match on a document whose linked publication is depublished is also dropped
- [ ] E2E test: seed a publication + a document with an attached PDF (or DOCX) carrying a distinctive phrase like `"lorem-ipsum-woo517-marker"`; poll the OR extraction job until chunks are indexed; assert `GET /apps/opencatalogi/api/search?_search=lorem-ipsum-woo517-marker&_content=true` returns the document row with correct `@self.schema=document` + embedded publication summary
  - Spec ref: SCH-PFTS-CONTENT-001, -002, -003
  - Acceptance: E2E green on both fresh MariaDB and fresh PostgreSQL dev envs (MariaDB may return unranked results — see deferred MariaDB-parity note in proposal.md)
- [ ] Update `docs/Integrations/fulltext-search.md` on `Conduction/openwoo-app-website` (twin PRs against `development` and `documentation` branches, matching the WOO-506 docs-branch pattern) — add a section describing the `_content=true` opt-in on Endpoint 2, its default OFF, and the fact that content matches share the same envelope + visibility rules as metadata matches
  - Spec ref: SCH-PFTS-CONTENT-001, -002, -003
  - Acceptance: both openwoo PRs open, both diffs identical
- [ ] Confirm archive gate: after impl + E2E green + docs merged, run `openspec archive add-document-content-search`
  - Spec ref: —
  - Acceptance: change moved under `openspec/changes/archive/YYYY-MM-DD-add-document-content-search`
