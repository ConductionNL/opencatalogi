---
kind: code
depends_on: []
---

# Proposal: publication-tells-its-source

## Why

A system that hands a record to OpenCatalogi (filinq, dossiq, integriq, or a municipality's own case system over the API) cannot tell its user where the record went, and cannot tell when it is done. It has to poll.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **1.17** "The product returns a record's public page and its officer page to the system that created it". Ours: no. Evidence: "openregister lib/Service/Object/SaveObject.php sets @self.uri on every object, but that is the object's API URL (linkToRoute on the objects route), not the public portal page nor the officer page; no create or show response in opencatalogi or portaliq returns either page, and grep for publicUrl/officerUrl/portalUrl in opencatalogi lib finds nothing".
- **1.20** "The source system is told when its record, with every document, is complete and public". Ours: partial, production. Evidence: "openregister lib/Listener/WebhookEventListener.php (registered in lib/AppInfo/Application.php on ObjectCreatedEvent and the other object events) delivers a CloudEvent to a subscribed URL with filters, retries and a delivery log, so a source system can be told its object changed. Nothing tells it that the publication is complete and public with every document".

Read on development at 35999c296. The public link already has one builder: `OCA\OpenCatalogi\Service\Portal\PublicationLinker::url()` (template, then portaliq, then the catalogue), used by `BatchPublicationWriter::url()` for REQ-WBP-001. The officer page is the route `ui#publicationsPage` (`/publications/{catalogSlug}/{id}`). OpenRegister's webhook filter (`WebhookService::passesFilters()`) compares payload keys for equality with dot notation, and an `ObjectUpdatedEvent` payload carries `newObject` and `oldObject`. So a source system can subscribe to one property moving from one value to another without OpenCatalogi sending anything itself.

## What changes

- The publication stores two read-only properties, `publicUrl` and `officerUrl`, set when it is created and refreshed when the link settings change. Because they are stored, every create and show response carries them, including OpenRegister's own object API that filinq, dossiq and integriq hand off through. OpenCatalogi's own publication endpoints compute them fresh.
- The publication stores `completeness`: `incomplete` or `complete`, with `completeAt`. OpenCatalogi sets `complete` when the publication is public and every document on it is public, and back to `incomplete` when that stops being true. An optional `expectedDocuments` lets the source say how many documents it will send, so a publication with one of three files is not complete.
- The move from `incomplete` to `complete` is an ordinary object update. A source system subscribes an OpenRegister webhook on `ObjectUpdatedEvent` with the filters `oldObject.completeness: incomplete` and `newObject.completeness: complete`, narrowed to its own records with `newObject.@self.owner`. OpenRegister delivers the CloudEvent with its retries and delivery log. OpenCatalogi sends nothing itself (ADR-022).
- A scheduled publication becomes public by date without a write. A background job re-evaluates completeness for publications whose publication date passed since its last run, so the event also fires for them.
- The integrations documentation gives the webhook recipe.

## Fail closed

- `complete` is never set while the publication is not public, while any attached file has no public share, or while fewer documents are attached than `expectedDocuments`. When completeness cannot be evaluated (a file lookup fails), the value stays as it was and the failure is logged. It is not set to `complete` on an error.
- When a public link cannot be built (no template, no portaliq, no catalogue), `publicUrl` is left empty. It is never filled with the API URL or a guess.
- A withdrawn file or a withdrawn publication moves `completeness` back to `incomplete`, so a later re-publication fires the event again.

## Out of scope

- A bespoke sender, a callback URL on the publication, or a push to filinq, dossiq or integriq. OpenRegister webhooks deliver.
- Source identifiers and the source system as fields. `publication-relations-place-and-source-ids` adds `sourceIdentifiers`; until then the owner of the object identifies the source.
- Registering OpenCatalogi's pages in OpenRegister's deep link registry.

## Dependencies

- None to build first. Uses what is on development: `PublicationLinker::url()`, OpenRegister `ObjectCreatingEvent` and `ObjectUpdatingEvent` (`setModifiedData()`), OpenRegister `FileMapper::getFilesForObject()` (each file carries `share_token`), and OpenRegister's webhook filters.
- Reads `PublicationStateService::stateOf()`. When `publication-lifecycle-on-or` (wave 1, planned) lands first, `stateOf()` also reads the stored lifecycle; this change needs no edit for that.

## Wave

Wave 1. It needs nothing that is not on development, and it is small.

## Decisions

None of D1 to D13 is implemented here. ADR-022 (apps consume OpenRegister abstractions) is why the notification is a webhook filter and not a sender.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 1.17 | The product returns a record's public page and its officer page to the system that created it | no | REQ-PTS-001, scenarios "A handoff gets both pages back" and "The show response carries both pages" |
| 1.20 | The source system is told when its record, with every document, is complete and public | partial | REQ-PTS-002 and REQ-PTS-003, scenario "The source system is told once, when the last document goes public" |
