# Tasks: add-public-fulltext-search

This change is `kind: mixed` (per ADR-032). Tasks are ordered so the schema declaration lands before the code that consumes it, mirroring a config → code chain even though it ships as one change.

- [x] Freeze the delta spec under `openspec/changes/add-public-fulltext-search/specs/search/spec.md` (MODIFIED SCH-OR-003 + ADDED SCH-PFTS-001 … SCH-PFTS-007); confirm `openspec validate add-public-fulltext-search` is green *(done in this PR — re-run `openspec validate` after any post-review spec tweaks before implementation begins)*
  - Spec ref: specs/search/spec.md (this change)
  - Acceptance: openspec validator reports "is valid"; SCH-OR-001/SCH-OR-002 untouched; SCH-OR-003 carried through the MODIFIED block
- [x] Add the `document` schema to `lib/Settings/publication_register.json` under `components.schemas` (alongside `publication`, `catalog`, `page`, `menu`, `theme`, `glossary`, `listing`, `organization`, `usageCounter`)
  - Spec ref: SCH-PFTS-005
  - Acceptance: schema present under `components.schemas.document`; `searchable: true`; `authorization.read` mirrors `publication`'s anonymous projection; magic mapper auto-allocates `oc_openregister_table_publication_document` on first install (no `configuration.schemas` block needed — the bundle's existing schemas rely on the same auto-allocation)
- [x] Add seed data per ADR-001 + design.md "Seed Data": 2 seed publications (municipality + consultancy scenarios) AND 2 seed documents linking to them. The current bundle ships NO seed publications, so this step adds both surfaces together. Each seed row MUST be self-identifying as demo data — `example-` slug prefix, `Voorbeeld:` title prefix, and a `**Voorbeeld / seed data**` banner opening the description.
  - Spec ref: ADR-001 (Seed Data) + design.md "Seed Data"
  - Acceptance: 4 new entries under `components.objects[]` with the bundle's canonical `@self: { register, schema, slug }` envelope (no top-level `id`); each document row carries an embedded `publication: { slug, title }` referencing one of the two new seed publications; every seed slug starts with `example-`, every seed title starts with `Voorbeeld:`, and every seed description opens with `**Voorbeeld / seed data**`; re-import is idempotent (matches on `@self.slug`); no real Dutch municipality names used (Gemeente Voorbeeld is fictional)
- [x] Promote `lib/Controller/SearchController::index()` to public — remove the anonymous-401 guard, annotate with `#[PublicPage]` + `#[NoCSRFRequired]`
  - Spec ref: SCH-PFTS-001
  - Acceptance: anonymous GET returns HTTP 200 with a result envelope; no path to HTTP 401 on the endpoint
- [x] Confirm the existing `GET /apps/opencatalogi/api/search` route entry in `appinfo/routes.php` is retained and its handler carries `#[PublicPage]` + `#[NoCSRFRequired]`; no new route path is added (SCH-PFTS-001 explicitly forbids introducing a new endpoint path for this purpose)
  - Spec ref: SCH-PFTS-001
  - Acceptance: existing route reused (no new entry added); `gate-route-reachability` and `gate-route-auth` both green
- [x] Add a new method on `PublicationQueryService` (e.g. `assemblePublicSearchResults()`) that calls OR's `zoeken-filteren` across both `publication` and `document` schemas and merges candidate rows into one flat array; do NOT filter the OR-side search surface down to a subset of properties. Placement is decided (see design.md "Service placement decision") — MUST live in `PublicationQueryService` so it can co-locate with the `isObjectPublic()` helper it reuses; no sibling service.
  - Spec ref: SCH-PFTS-002, SCH-PFTS-006, SCH-PFTS-007
  - Acceptance: response envelope is a single array; each row carries `@self.schema`; no separate `publications`/`documents` sub-arrays; matches surface across every `searchable` property of both schemas (verified by a test that adds a new string property to one of the seed rows and confirms it matches without controller changes)
- [x] Embed `publication: {id, slug, title}` (English `title` — matches the bundled publication schema) on each document row during assembly
  - Spec ref: SCH-PFTS-003
  - Acceptance: every document row in the response carries the embedded publication summary with English field names; rows whose linked publication is missing are dropped. **Note the seed-vs-response asymmetry** — seed rows in `publication_register.json` carry `publication.{slug, title}` (no `id`, because the magic mapper assigns UUIDs at import time; see design.md "Seed publications"); API-response rows MUST carry `publication.{id, slug, title}`. A diligent implementer testing the seed shape should not expect `id` to be present in the seed JSON.
- [x] Apply `isObjectPublic()` to anonymous results AFTER scoring/merge (post-filter, never folded into the OR query)
  - Spec ref: SCH-PFTS-004
  - Acceptance: PHPUnit test demonstrates that a candidate row excluded by visibility was present pre-filter and absent post-filter; ordering invariant documented in the helper's docblock
- [x] Enforce transitive visibility on documents: drop document rows whose linked publication fails `isObjectPublic()`
  - Spec ref: SCH-PFTS-004
  - Acceptance: PHPUnit test with a document whose linked publication is depublished; document MUST NOT surface for anonymous callers
- [x] Add Newman / PHPUnit tests proving anonymous reachability + 200 status on `/apps/opencatalogi/api/search`
  - Spec ref: SCH-PFTS-001
  - Acceptance: tests run in CI; cover the no-auth-header and depublished-row cases
  - Note: shipped as PHPUnit (`SearchControllerTest`/`PublicationQueryServiceTest`), not a new Newman collection — the task explicitly allows either.
- [x] Add regression coverage proving `GET /publications` is unchanged (existing assertions still pass; no new fields, no removed fields, no admission-rule drift)
  - Spec ref: SCH-PFTS-001 (negative)
  - Acceptance: existing publications endpoint tests run unmodified; no diff in their fixtures
  - Note: `PublicationsController.php` / `PublicationsControllerTest.php` are untouched by this change; the pre-existing suite is the regression proof.
- [x] Document Path A vs Path B in the controller / service docblock (linking to design.md's dual-path section) so future implementers know which extraction surface to wire when the follow-up lands
  - Spec ref: design.md "Dual-path design"
  - Acceptance: docblock comment references the WOO-517 follow-up ticket (content-indexing, assigned Ruben, in Refinement per the issue's current task 12); no extraction code added in this change
- [x] Update `openspec/specs/search/spec.md` (the canonical capability) to list this change under an `**OpenSpec changes**` block with status `in-progress` *(done in this PR)*
  - Acceptance: spec carries a canonical `**Status**: in-progress / **Scope**: opencatalogi / **OpenSpec changes**:` block listing `add-public-fulltext-search`
- [ ] Add a `CHANGELOG.md` entry noting the shape change on `GET /apps/opencatalogi/api/search`: (a) mixed publication + document rows discriminated by `@self.schema`, (b) anonymous reachability replacing the prior HTTP 401 posture, (c) the prior admin-only response shape is removed. Cross-reference WOO-506 and mention that any lingering admin consumer of the old shape needs to switch.
  - Spec ref: proposal.md "Risks" (admin backwards-compat break)
  - Acceptance: `CHANGELOG.md` carries a dated entry under the next release section; WOO-506 stakeholders in the ticket are pinged with a link to the entry
  - Scope reduction: left unchecked per ADR-037 (modular config fragments) — `CHANGELOG.md` is release-step-owned and editing it in a builder PR guarantees a merge conflict against sibling in-flight builds on this app. The shape-change note is carried instead in this PR's description ("Risks" / backwards-compat section); the release step should fold it into the next dated entry at release time.
- [ ] Run the Hydra mechanical gates locally (`scripts/run-hydra-gates.sh --scope-to-diff`) and resolve every BLOCKING issue before opening the PR
  - Acceptance: all 45 gates either PASS or carry a documented exclusion
- [ ] Open the PR; confirm reviewer + security-reviewer pass; address inline review nits without scope creep
  - Acceptance: PR review verdict is APPROVE on both lanes
  - Note: PR opened by this build; reviewer/security-reviewer verdicts land in subsequent pipeline stages, not in the builder run.
- [ ] Confirm Ruben's answer received + (if needed) open a follow-up OpenSpec change before archiving this one
  - Acceptance: Ruben's decision on document content indexing is recorded in WOO-506; if "no/later", a B3 change (e.g. `add-document-content-search`) is created and linked from this change's proposal before archive

## Quality checklist

- spec validates (`openspec validate add-public-fulltext-search`)
- `gate-route-auth`, `gate-route-reachability`, `gate-spdx`, `gate-stub-scan`, `gate-forbidden-patterns` all green
- Anonymous visibility filter (`isObjectPublic()`) runs on every result row — enforced post-scoring per SCH-PFTS-004; verified by the transitive-visibility PHPUnit tests (task 8 / task 9). Note: `gate-no-admin-idor` targets `#[NoAdminRequired]` controllers and will NOT fire on `SearchController::index` after it carries `#[PublicPage]` — the visibility guarantee is provided by the tests + the `isObjectPublic()` post-filter, not the gate.
- regression suite against `/publications` passes unmodified
- seed data installs on a fresh container (`clean-env` recipe)
- Ruben's pending-decision answer is recorded before `openspec archive` runs
