# Proposal: leaf-integrations

## Summary

Adopt OpenRegister's app-agnostic integration leaves beyond the three OpenCatalogi uses today. Current verified state (`src/manifest.json`): **files** (PublicationDetail "Attachments"), **photos** (PublicationDetail "Images", OrganizationDetail "Logo & images"), **bookmarks** (PublicationDetail "Links") — and no schema declares `linkedTypes` or any mail configuration. This change adds four leaves where they genuinely serve the publishing workflow: **mail** (publication requests arriving by email become draft publications — `configuration.linkedTypes: ["mail"]` makes `publication` a Mail-sidebar link target, and `configuration.mailObjectTemplate` puts a create-publication-from-email button in the NC Mail sidebar), **calendar** (planned publication/depublication dates as linked events on PublicationDetail), **forms** (a publication-submission intake form linked at the organization level), and **contacts** (an organization's contact persons as linked NC Contacts cards). Everything is declarative: schema `configuration` keys in `lib/Settings/publication_register.json` plus manifest integration widgets — no new PHP, no new Vue.

## Motivation

- **Publication requests arrive by email today and are retyped by hand.** A WOO/transparency desk receives requests and source material in a shared mailbox; the `mailObjectTemplate` mechanism (verified in openregister `lib/Db/Schema.php`: a flat property→template map with `{{subject}}`/`{{sender}}`/`{{preview}}`/`{{date}}` placeholders; only schemas declaring the template get the button) exists precisely for this and no schema in the fleet's publishing app uses it yet.
- **Publication planning happens in calendars nobody links.** Editors plan "publish the council decisions Friday, depublish the draft after recess" in personal agendas; the authoritative fields (`publicationDate`, `depublicationDate` — verified properties of `publication`) then get set late or wrong. A calendar leaf ties the planning events to the publication object without touching the authoritative fields.
- **The leaf infrastructure is already paid for.** OpenRegister ships the providers (`EmailProvider`, `CalendarProvider`, `FormsProvider`, `ContactsProvider`, verified in `lib/Service/Integration/Providers/`), the `Schema::linkedTypes` filter and the manifest widget render path; OpenCatalogi already renders four integration widgets, so the pattern is proven in this app.

## Affected Projects

- [ ] Project: opencatalogi — `lib/Settings/publication_register.json` (configuration keys on `publication` and `organization`), `src/manifest.json` (3 new widgets), one new e2e spec-coverage file
- [ ] Project: openregister — **no code change**; consumed read-only as the leaf registry, Mail-sidebar host, and render layer

## Capabilities

- `integration-leaves` — new capability: which OpenRegister integration leaves OpenCatalogi declares, the mail-intake contract, and why the rest are OFF

## Scope

### In Scope

- `configuration.linkedTypes: ["mail"]` + `configuration.mailObjectTemplate` on the `publication` schema (mail intake; the template maps `title`/`summary`/`description` from `{{subject}}`/`{{preview}}`/sender-and-date prose and deliberately never sets `publicationDate`, so an emailed request can never arrive published)
- `calendar` in `publication`'s linked types + one calendar widget on PublicationDetail (planning events; the authoritative gate stays the `publicationDate` property)
- `forms` + `contacts` in `organization`'s linked types + one widget each on OrganizationDetail (submission-intake form; contact persons)
- A Playwright spec-coverage test for the new widgets
- Documentation of the OFF list with reasons

### Out of Scope

- Any change to `publication.authorization`, `autoPublish`, or the publication gate itself — the mail template creates **unpublished** objects and nothing in this change can publish
- Mail intake on `document`, `catalog`, or any other schema (one intake archetype first; see Open Questions)
- Auto-creating calendar events from `publicationDate`/`depublicationDate` (derivation needs an idempotency design; the leaf links user-curated events)
- Deck/talk/polls/maps/shares/collectives/notes/time-tracker/analytics leaves — no publishing workflow asked for them (see design OFF list)
- Changes to OpenRegister providers or the Mail sidebar itself

## Approach

1. Add the `configuration` keys to `publication` and `organization` in `lib/Settings/publication_register.json`, alongside the existing `autoPublish` key (both schemas already carry a `configuration` object, verified).
2. Add three manifest widgets shaped like the existing four: PublicationDetail `pub-calendar` (calendar), OrganizationDetail `org-intake-form` (forms) and `org-contacts` (contacts).
3. Add `tests/e2e/spec-coverage/integration-leaves.spec.ts`.

## New Dependencies

None. The Mail, Calendar, Forms, and Contacts NC apps are runtime-optional: providers self-disable (`isEnabled()`) when the app is absent, and the Mail-sidebar surface only exists inside the Mail app.

## Impact

- `lib/Settings/publication_register.json` is re-imported; the added keys are validated by `Schema::validateLinkedTypesValue()` / `validateMailObjectTemplateValue()` at import and touch no property, no `authorization` block, no `autoPublish` value.
- 3 new manifest widgets (total integration widgets: 7); no existing widget changes.
- A new object-creation path for `publication` (from email). Objects so created are ordinary draft publications: no `publicationDate`, invisible to the `public` group per the existing RBAC match rule, editable in the normal UI.

## Cross-Project Dependencies

- **openregister** ≥ the commit carrying the pluggable integration registry, the Mail sidebar (`src/mail-sidebar/`, `CreateConnectedObjectDialog`), and `mailObjectTemplate` validation (all present at `origin/development`).

## Risks

### Risk 1: Mail intake creates publications from arbitrary inbound mail

- **Severity**: Medium
- **Detail**: Anyone can email the desk; the create-from-email button turns a hostile or spam mail into a register object containing attacker-chosen text.
- **Mitigation**: The button is a **user action** inside the NC Mail sidebar — a human clicks it per email; nothing is automatic. The created object is unpublished by construction (REQ-002: the template MUST NOT contain `publicationDate`, `depublicationDate`, or `status`), so nothing reaches the public surface, the sitemap, or the DCAT harvester without the normal editorial publish act.

### Risk 2: A calendar leaf is mistaken for the publication gate

- **Severity**: Medium
- **Detail**: An editor links a "publish Friday" event, Friday passes, nothing publishes — or worse, someone assumes deleting the event depublishes.
- **Mitigation**: REQ-003 pins that the leaf is planning-only and the widget copy says "Planning (does not publish)"; the authoritative fields stay `publicationDate`/`depublicationDate`, unchanged by any leaf action.

### Risk 3: Contact cards drift from the register's own organization fields

- **Severity**: Low
- **Detail**: `organization` has `name`, `oin`, `tooi`, `rsin` etc.; a linked contact card carries its own name/email/phone that nobody reconciles.
- **Mitigation**: The leaf links, never copies (REQ-004); register properties remain the authoritative organization identity, the card is reachability info for humans.

## Rollback Strategy

Revert the commit. Re-importing the register drops the added configuration keys (nothing else in the schemas moves); the widgets disappear with the manifest. Publications already created from email are ordinary objects and remain, as intended. Linked events/forms/cards live in their NC apps and only lose their OpenCatalogi-side rendering.

## Open Questions

- Should `document` also become a mail link target (a request email often carries the bijlage as attachment)? Deferred: attachment-to-file intake is a different mechanism than property templating.
- Should the intake form (`forms` leaf) eventually feed a draft publication automatically (Forms → OpenConnector → OpenRegister)? That is an openconnector flow, not a leaf; noted for the openconnector backlog.
- Does the mail-derived `description` need a fixed provenance suffix ("Received by email from … on …") as normative spec text, or is template guidance enough? Currently normative in REQ-002.
