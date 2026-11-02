# Tasks

Read `openspec/woo-build-rules.md` first. Read the projection's real keys in openregister (`lib/Repair/SeedDirectoryVirtualSchemas.php`, `OrganisationObjectSourceProvider`) before writing fixtures. Use a valid test RSIN that passes the 11-proef (002220647).

## 1. Pages on the shared organisation

- [ ] 1.1 Point the `Organizations` and `OrganizationDetail` pages in `src/manifest.json` (lines 1035 and 1060) at `"register": "@resolve:organization_register"` and `"schema": "@resolve:organization_schema"`; keep `showDeleteAction` and `showMassDelete` false (REQ-SHO-103). Verify: `tests/vitest/organisationsPage.spec.js` asserts both pages resolve the organisation keys and neither names the schema `organization`.
- [ ] 1.2 Check that `SettingsService` resolves `organization_register` and `organization_schema` to the directory register and `nc-organisation` on a fresh install and after an upgrade (task 2.2 of `publications-reference-the-shared-organisation`); add the two keys to the manifest's `@resolve` set if the loader does not know them (REQ-SHO-103). Verify: `tests/Unit/Service/SettingsServiceTest.php::testOrganisationKeysResolveToTheProjection`.
- [ ] 1.3 Set the detail page's `org-data` widget to show `name`, `summary`, `oin`, `tooi`, `rsin`, `pki` in two columns, and gate its edit action on the projection's write answer (REQ-SHO-103). Verify: `tests/vitest/organisationsPage.spec.js` asserts the field list.

## 2. Live and end to end

- [ ] 2.1 Live on the dev instance: as organisation administrator enter RSIN 002220647, read it back from `GET /apps/openregister/api/objects/directory/nc-organisation/{id}`, and paste the read-back in the PR body (REQ-SHO-103). Verify: the pasted read-back.
- [ ] 2.2 Playwright: `tests/e2e/organisations-page.spec.ts` "an administrator enters the RSIN" and "another user reads but cannot edit", carrying `@e2e` REQ-SHO-103.

## 3. Docs and parity

- [ ] 3.1 Document where staff enter the identifiers in `docs/` and the strings in `l10n/` (en, nl). Verify: `npm run check:l10n` and no U+2014 in the changed docs.
- [ ] 3.2 Set `cat-organisation` to `built` in `openspec/parity/capabilities.json` with file evidence once merged.

## 4. Verification

- [ ] 4.1 Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, then `npm run lint` and `npm run check:manifest`. One PR with `--base development`; no `Co-Authored-By`.
