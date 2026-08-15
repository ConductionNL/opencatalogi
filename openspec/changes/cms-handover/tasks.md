# Tasks: cms-handover

> Hand the website CMS to Portaliq, leave a deprecated read path
> (ADR-032 `kind: code`). Checkbox budget: 4 tasks × 2 = 8 unindented
> `- [ ]` lines (cap 20).

## Implementation Tasks

### Task 1: Record the current behaviour before changing it
- **spec_ref**: `openspec/changes/cms-handover/specs/cms-handover/spec.md#requirement-the-read-path-must-stay-behaviour-identical-while-deprecated`
- **files**: `tests/fixtures/cms-baseline/*.json`, `tests/Unit/Controller/CmsProxyParityTest.php`
- **acceptance_criteria**:
  - Responses from `MenusController`, `PagesController` and `GlossaryController` are recorded from the CURRENT implementation, before any edit, covering two-level menus, ordered page content and glossary terms
  - The recordings are the assertion target for the proxy — comparing the proxy against the new implementation's expectations would prove only that two new things agree
  - Recordings come from a deployment with real data, not from fixtures written to match the schema
- [ ] Implement
- [ ] Test

### Task 2: Reduce the controllers to deprecated proxies
- **spec_ref**: `openspec/changes/cms-handover/specs/cms-handover/spec.md#requirement-the-read-path-must-stay-behaviour-identical-while-deprecated`
- **files**: `lib/Controller/MenusController.php`, `lib/Controller/PagesController.php`, `lib/Controller/GlossaryController.php`
- **acceptance_criteria**:
  - Each delegates to Portaliq's content API and matches its recorded baseline
  - Each is marked deprecated in the response and in its docblock
  - Portaliq unavailable reports the failure — it does NOT return an empty success, which a consumer reads as "no menus" and renders as a site with no navigation
- [ ] Implement
- [ ] Test

### Task 3: Remove the CRUD UI and account for other glossary consumers
- **spec_ref**: `openspec/changes/cms-handover/specs/cms-handover/spec.md#requirement-remaining-glossary-consumers-must-be-identified-before-removal`
- **files**: `src/views/menus/`, `src/views/pages/`, `src/views/glossary/`, `src/modals/{menu,menuItem,page,pageContents,glossary}/`, `src/dialogs/{menu,page}/`, `src/manifest.json`
- **acceptance_criteria**:
  - The 31 menu/page/glossary frontend files are removed and the manifest no longer routes to them; an admin reaching the old location is told where the capability lives
  - Every glossary reference OUTSIDE the CMS UI — `ProvideManifestConfigStateListener`, `SettingsService`, `UiController`, `EntityDetailPage.vue`, `Modals.vue`, `navigation.ts` — is enumerated FIRST and each is migrated or documented as retained
  - No such consumer is discovered after deletion
  - Catalogues, publications, themes and directory federation are exercised and unchanged
- [ ] Implement
- [ ] Test

### Task 4: Write the removal change now, not later
- **spec_ref**: `openspec/changes/cms-handover/specs/cms-handover/spec.md#requirement-proxy-removal-must-be-scheduled-as-its-own-change`
- **files**: `openspec/changes/cms-proxy-removal/`
- **acceptance_criteria**:
  - The removal change exists with proposal and tasks, naming the three controllers and the release after which they go
  - It is written in this change, because a deprecation with no scheduled removal becomes permanent
- [ ] Implement
- [ ] Test
