# Design: leaf-integrations

## Context

OpenRegister's integration-leaf machinery (verified at `origin/development`):

- ~17 providers under `lib/Service/Integration/Providers/` (ids include `email`, `calendar`, `contacts`, `forms`, `deck`, `polls`, `talk`, `maps`, `photos`, `shares`, `bookmarks`, `collectives`, `notes`, `activity`, `time-tracker`, `analytics`) plus built-ins (`files`, `tags`, `tasks`, `notes`, `audit-trail`).
- Stage 2 of the three-stage filter reads the schema; stage 3 is the manifest integration widget.
- The Mail sidebar (`src/mail-sidebar/`, `CreateConnectedObjectDialog.vue`) is a distinct surface: `configuration.linkedTypes: ["mail"]` marks a schema as a link target from an email, and `configuration.mailObjectTemplate` (validated by `Schema::validateMailObjectTemplateValue()` — flat map, scalar values, `{{subject}}`/`{{sender}}`/`{{senderName}}`/`{{date}}`/`{{date30}}`/`{{datetime}}`/`{{preview}}`/`{{messageId}}`/`{{mailRef}}` placeholders) adds the create-object-from-email button. Only schemas declaring the template get the button.

OpenCatalogi today (verified): 4 integration widgets (`pub-files`, `pub-photos`, `pub-links`, `org-image`); no `linkedTypes` and no mail configuration anywhere in `lib/Settings/publication_register.json`; every schema's `configuration` object currently holds `autoPublish: false` (plus `implements` on `organization`, `x-openregister-shareable` on `catalog`).

The load-bearing fact for everything below: **publication state is a data field.** `publication.authorization.read` is `[{"group": "public", "match": {"publicationDate": {"$lte": "$now"}}}, "authenticated"]` — an object without `publicationDate` is invisible to the public by construction.

## Goals / Non-Goals

**Goals**
- Email → draft publication in one click, with publication impossible from that path.
- Publication planning events, intake forms, and organization contacts linked where editors work.
- Zero PHP, zero Vue; the whole leaf surface enumerable from two JSON files.

**Non-Goals**
- Automating publication (that is `auto-publishing`'s capability and the hermiq-ai-tooling change's governed tools — not leaves).
- Mail intake for schemas other than `publication`.
- Deriving calendar events from date properties.

## Decisions

### Decision 1: The ON matrix

| Schema | New configuration | Widget (page) | Why |
|---|---|---|---|
| `publication` | `linkedTypes: ["mail", "calendar"]`, `mailObjectTemplate` | `pub-calendar` on PublicationDetail, title "Planning (does not publish)" | Mail is the intake channel that exists in practice; calendar is where publish/depublish planning already lives. The mail leaf itself renders in the NC Mail sidebar, not as an OpenCatalogi widget — hence one widget, two linked types. |
| `organization` | `linkedTypes: ["forms", "contacts"]` | `org-intake-form`, `org-contacts` on OrganizationDetail | The submitting organization is the stable anchor for an intake form (there is no CatalogDetail page — verified: manifest detail pages are Publication, Organization, Theme, Glossary, Page, Menu) and for contact persons. |

### Decision 2: The mail template maps only safe, prose-shaped properties

```
"mailObjectTemplate": {
  "title": "{{subject}}",
  "summary": "{{preview}}",
  "description": "Received by email from {{senderName}} <{{sender}}> on {{date}}.\n\n{{preview}}"
}
```

All three keys are verified `publication` properties. The template deliberately omits `publicationDate`, `depublicationDate`, `status`, `organization`, `themes`, and every retention field: dates because their presence is the publication gate (Risk 1 of the proposal — this is the same property-is-the-gate fact that made `opencatalogi-mcp-adoption` refuse write verbs), `status` because its enum (`published|archived`) has no draft value and an emailed request is neither, and the classification fields because a human editor must classify. REQ-002 makes the omissions normative.

### Decision 3: Calendar is planning, never mechanism

The calendar leaf links VEVENTs to a publication. It does not read or write `publicationDate`. Rationale: two clocks ("planned" vs "actual") diverge in every real newsroom/desk; conflating them would either make a calendar drag-drop a publish act (an authorization hole — publishing would move from the guarded property write to any calendar editor) or make the property write move an event nobody owns. Divergence is a feature: the leaf shows the plan, the property shows the fact.

### Decision 4: The OFF list

- `email` as a **leaf on detail pages** (as opposed to the Mail-sidebar intake): deferred — the sidebar intake is the workflow that exists; a linked-emails tab on PublicationDetail is plausible but unrequested.
- `deck`, `talk`, `polls` — publishing is not card- or conversation-shaped in this app's workflow today; adding comms surfaces to a records app needs a real user ask.
- `maps` — no geo property on any schema.
- `shares`, `collectives`, `notes`, `activity`, `time-tracker`, `analytics`, `tasks` — generic; nothing asked for them; every widget costs page space.
- `photos`, `bookmarks`, `files` — already adopted; untouched.
- Mail intake on `document` / `catalog` / `page` — one intake archetype first (proposal Open Questions).

### Decision 5: No PHP

The Mail sidebar, template expansion, linked-type validation, and widget rendering are all OpenRegister's. OpenCatalogi's change is register JSON + manifest JSON + one e2e file. If a future change wants intake-time classification (auto-assign `organization` from the sender domain, say), that is a listener and its own change.

## Risks / Trade-offs

- Mail-created drafts can accumulate unclassified (no `organization`, no `themes`). Accepted: they are visible only to authenticated editors, and the existing editorial list views surface them; a cleanup view is a follow-up if volume warrants.
- The intake form leaf links a form; submissions do not become objects by themselves. Accepted and documented (proposal Open Questions — an openconnector flow can close that loop later).

## Migration Plan

Pure addition; register re-import applies the configuration keys. Rollback = revert (mail-created publications remain as ordinary drafts, correctly).

## Open Questions

Carried in proposal.md.
