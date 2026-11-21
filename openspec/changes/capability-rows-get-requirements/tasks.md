# Tasks: capability-rows-get-requirements

## 1. Requirements that describe built behaviour

- [ ] 1.1 Name or add the e2e test for REQ-CRR-001 (create a publication with title, summary, description and themes) and sync it into `openspec/specs/publications`. Written: `tests/e2e/publication-create.spec.ts` (Add with title, summary, description and a seeded theme, read back as a draft; a form without a title refused); not run, needs a live instance.
- [x] 1.2 Name or add the PHPUnit or e2e test for REQ-CRR-002 (one publication in two organisations' catalogues) and sync it into `openspec/specs/catalogs`.
- [ ] 1.3 Name or add the e2e test for REQ-CRR-004 (Reimport and Force import on the settings page) and sync it into `openspec/specs/retrofit-2026-05-26-app-shell-settings`. Written: `tests/e2e/settings-configuration-import.spec.ts` (Force import posts `force: true` and the page reports the result, Reimport posts `force: false`); not run, needs a live instance.

## 2. Requirements still to build

- [x] 2.1 In `lib/Service/RetentionService.php`, hand an archive decision to OpenRegister's e-Depot transfer and store the transfer reference on the publication (REQ-CRR-003). Set row `lc-archive` to `built` when the test is green.
- [x] 2.2 In `src/views/dashboard/Dashboard.vue`, show the catalogue count and the attachment count on the app's dashboard next to the publication counts (REQ-CRR-005). Set row `ops-dashboard` to `built` when the test is green.
