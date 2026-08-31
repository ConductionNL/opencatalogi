# Tasks: leaf-integrations

## Implementation Tasks

### Task 1: Declare the leaf configuration on `publication` and `organization` (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-a-publication-created-from-email-is-unpublished-by-construction-req-002`
- **files**: `lib/Settings/publication_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN edited THEN `publication.configuration` carries `linkedTypes: ["mail", "calendar"]` and the REQ-002 `mailObjectTemplate` (key set exactly `{title, summary, description}`), and `organization.configuration` carries `linkedTypes: ["forms", "contacts"]` — each added **alongside** the existing keys (`autoPublish`, `implements`), which are byte-identical before/after
  - GIVEN the file WHEN grepped for `linkedTypes`/`mailObjectTemplate` THEN only `publication` and `organization` carry them; `document`, `catalog`, `listing`, `page`, `theme`, `menu`, `glossary`, `usageCounter` carry neither
  - GIVEN the template WHEN inspected THEN it contains no `publicationDate`, `depublicationDate`, `status`, `organization`, `themes`, or retention key
  - GIVEN each edit WHEN `python3 -m json.tool lib/Settings/publication_register.json` runs THEN it exits 0 and no pre-existing key is dropped
- [ ] Implement
- [ ] Test

### Task 2: Add the three manifest widgets (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-the-calendar-leaf-on-publications-is-planning-only-and-never-the-gate-req-003`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN edited THEN PublicationDetail gains `pub-calendar` (`integrationId: "calendar"`, title "Planning (does not publish)") and OrganizationDetail gains `org-intake-form` (`integrationId: "forms"`) and `org-contacts` (`integrationId: "contacts"`), each shaped like the existing `pub-files` widget (`id`, `type: "integration"`, `integrationId`, `title`, `icon`)
  - GIVEN the manifest WHEN grepped for `"type": "integration"` THEN the count is 7 and the four pre-existing widgets (`pub-files`, `pub-photos`, `pub-links`, `org-image`) are unchanged
  - GIVEN the widgets and the register template WHEN reviewed THEN no leaf has a write path to `publicationDate`/`depublicationDate`/`status` (REQ-003/REQ-004 review criterion)
- [ ] Implement
- [ ] Test

### Task 3: e2e spec-coverage for mail intake and the new widgets (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-a-publication-created-from-email-is-unpublished-by-construction-req-002`
- **files**: `tests/e2e/spec-coverage/integration-leaves.spec.ts`
- **acceptance_criteria**:
  - GIVEN the Mail, Calendar, Forms, and Contacts apps enabled in the test env WHEN the suite runs THEN it covers: create-publication-from-email (draft exists; public/anonymous request for it is denied; absent from sitemap), the planning-event link on PublicationDetail (publication stays unpublished), and widget presence on OrganizationDetail
  - GIVEN a leaf NC app is disabled WHEN the page renders THEN the test tolerates the absent widget (provider `isEnabled()` behaviour)
- [ ] Implement
- [ ] Test

### Task 4: Documentation (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-in-the-register-and-the-manifest-nowhere-else-req-001`
- **files**: `docs/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `docs/` WHEN read THEN it records the mail-intake flow for desk employees (with the "arrives as draft, publish is the normal editorial act" framing), the planning-vs-gate distinction, and the OFF list with reasons
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the four new leaves
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate leaf-integrations --type change --strict` passes
- [ ] Manual testing against acceptance criteria (create a publication from a real email; link an event, a form, a contact)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] Browser tests (Playwright MCP): `tests/e2e/spec-coverage/integration-leaves.spec.ts` (Task 3)
- [ ] All tests pass; zero new failures vs a self-measured baseline
- PHPUnit: N/A — this change ships no PHP.
- Newman/Postman: N/A — no HTTP endpoint is added; the mail-intake write goes through OpenRegister's existing object API.

## Documentation (company-wide ADR-010)
- [ ] `docs/` records the intake flow and the leaf matrix (Task 4)
- [ ] Screenshot of the Mail-sidebar create-publication button and the PublicationDetail planning widget committed to `docs/images/`

## i18n (company-wide ADR-005)
- [ ] Widget titles ("Planning (does not publish)", "Intake form", "Contacts") are new user-facing strings: `nl_NL` and `en_US` entries added via the mechanism the existing widget titles use. The `mailObjectTemplate` provenance string is stored object data, not UI copy — it ships in English per the all-code-is-English rule.
