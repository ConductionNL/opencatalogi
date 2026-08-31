# Integration Leaves

Which OpenRegister integration leaves OpenCatalogi declares, the mail-intake contract for publications, and why the rest are OFF. A leaf is declared in `lib/Settings/publication_register.json` (schema `configuration`) and rendered either by a manifest integration widget or, for mail, by OpenRegister's Mail-sidebar surface. OpenCatalogi ships no leaf provider code.

## ADDED Requirements

### Requirement: Leaves are declared in the register and the manifest, nowhere else (REQ-001)
Every OpenCatalogi integration leaf MUST consist solely of schema `configuration` keys in `lib/Settings/publication_register.json` (`linkedTypes`, and for mail additionally `mailObjectTemplate`) plus manifest integration widgets in `src/manifest.json`. OpenCatalogi MUST NOT implement an `IntegrationProvider` and MUST NOT add per-leaf Vue components. The declared surface after this change MUST be exactly: `publication` → `linkedTypes: ["mail", "calendar"]` + `mailObjectTemplate`; `organization` → `linkedTypes: ["forms", "contacts"]`; widgets `pub-calendar` (PublicationDetail), `org-intake-form` and `org-contacts` (OrganizationDetail) added beside the four pre-existing integration widgets, which MUST NOT change. No other schema may carry `linkedTypes` or `mailObjectTemplate`.

#### Scenario: The leaf surface is enumerable from two files

- GIVEN the repository at this change's completion,
- WHEN `lib/Settings/publication_register.json` is searched for `linkedTypes`/`mailObjectTemplate` and `src/manifest.json` for `"type": "integration"`,
- THEN the results MUST list exactly the surface above (7 integration widgets total),
- AND `lib/` MUST contain no `IntegrationProvider` implementation.

> @e2e exclude static repo-shape assertion — verified by the task acceptance-criteria greps and the register-import validation, not a DOM behaviour.

#### Scenario: The register imports with valid leaf declarations

- GIVEN the edited register,
- WHEN it is imported into OpenRegister,
- THEN `Schema::validateLinkedTypesValue()` and `Schema::validateMailObjectTemplateValue()` MUST accept every declared value,
- AND no pre-existing configuration key (`autoPublish`, `implements`, `x-openregister-shareable`) is dropped or changed.

> @e2e exclude backend import validation — covered by OpenRegister's validators; asserted via the JSON round-trip check in Task 1.

### Requirement: A publication created from email is unpublished by construction (REQ-002)
The `publication` schema MUST declare `configuration.linkedTypes` containing `"mail"` and a `configuration.mailObjectTemplate` mapping exactly `title` → `"{{subject}}"`, `summary` → `"{{preview}}"`, and `description` → a provenance string naming `{{senderName}}`, `{{sender}}`, and `{{date}}` followed by `{{preview}}`. The template MUST NOT contain `publicationDate`, `depublicationDate`, `status`, `organization`, `themes`, or any retention property. Rationale (binding): `publication.authorization.read` grants the `public` group access via `{"publicationDate": {"$lte": "$now"}}` — the date property IS the publication gate — so an object created without it is invisible to the public, absent from the sitemap, and ignored by the DCAT harvester until an editor performs the normal publish act. Creating a publication from an email MUST remain a per-email human action in the NC Mail sidebar; nothing in this capability may create objects from mail automatically.

#### Scenario: An emailed publication request becomes a draft, not a publication

- GIVEN a desk employee viewing a request email in NC Mail,
- WHEN they use the create-publication-from-email button,
- THEN a `publication` object exists with `title` from the subject, `summary` from the preview, and a provenance `description`,
- AND the object has no `publicationDate` and is not readable by the `public` group, not in the sitemap, and not harvested.

@e2e tests/e2e/spec-coverage/integration-leaves.spec.ts

#### Scenario: The template cannot smuggle publication state

- GIVEN the `mailObjectTemplate` in the imported register,
- WHEN its key set is inspected,
- THEN it MUST equal exactly `{title, summary, description}`,
- AND adding any date, status, or classification key to the template is a violation of this requirement, not a configuration choice.

> @e2e exclude static template-shape assertion — covered by the Task 1 acceptance criteria; the runtime consequence (draft invisibility) is the previous scenario.

### Requirement: The calendar leaf on publications is planning-only and never the gate (REQ-003)
The `publication` schema MUST declare `"calendar"` in `configuration.linkedTypes`, and PublicationDetail MUST carry one calendar integration widget titled "Planning (does not publish)". The leaf links user-curated CalDAV events to a publication for publish/depublish planning. No leaf action may read or write `publicationDate`, `depublicationDate`, or `status`: linking, editing, or deleting an event MUST leave the publication's visibility unchanged, and the authoritative publish/depublish acts remain the guarded property writes they are today.

#### Scenario: An editor plans a publish date without publishing

- GIVEN an unpublished publication,
- WHEN an editor links a "publish Friday" calendar event via the leaf widget,
- THEN the event is shown on PublicationDetail,
- AND the publication remains unpublished when Friday passes (no property changed).

@e2e tests/e2e/spec-coverage/integration-leaves.spec.ts

#### Scenario: Deleting a planning event depublishes nothing

- GIVEN a published publication with a linked planning event,
- WHEN the event is unlinked or deleted,
- THEN `publicationDate` is unchanged and the publication remains publicly readable.

> @e2e exclude negative-coupling assertion — the leaf has no write path to object properties by construction (OpenRegister `CalendarProvider` links VEVENTs only); covered by the Task 2 review criterion and OpenRegister's provider tests.

### Requirement: Organization leaves link intake forms and contact persons without copying data (REQ-004)
The `organization` schema MUST declare `"forms"` and `"contacts"` in `configuration.linkedTypes`, with one forms widget (publication-submission intake) and one contacts widget (contact persons) on OrganizationDetail. Both leaves link entities owned by their NC apps: form definitions and submissions stay in Forms; contact cards stay in Contacts. No register property may be written from a linked entity and no card or form field may be copied into the register by the leaf; the `organization` properties (`name`, `oin`, `tooi`, `rsin`, …) remain the authoritative organization identity. Leaves MUST render only after the OpenRegister RBAC read of the organization object succeeds.

#### Scenario: A desk links the intake form and a contact person

- GIVEN an organization object,
- WHEN an editor opens OrganizationDetail,
- THEN the forms leaf lets them link the organization's submission-intake form and the contacts leaf lets them link a contact card,
- AND the organization object's own properties are unchanged by either linking act.

@e2e tests/e2e/spec-coverage/integration-leaves.spec.ts

#### Scenario: Form submissions do not become publications by themselves

- GIVEN a linked intake form with new submissions,
- WHEN the submissions are inspected,
- THEN they exist only in the Forms app,
- AND no publication object was created by the leaf (closing that loop is an OpenConnector flow, out of this capability's scope).

> @e2e exclude absence-of-side-effect assertion — the leaf has no object-creation path by construction; covered by the Task 2 review criterion.
