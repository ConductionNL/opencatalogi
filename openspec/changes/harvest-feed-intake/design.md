# Design: harvest-feed-intake

An administrator registers a DCAT JSON-LD feed. OpenRegister runs it as a harvest flow. Each dataset becomes a draft publication with its source on it. Since spec round part 2 (7 October 2026, decision 80) the harvest machinery is OpenRegister's, specified in `openregister/app-harvest-fetchers-and-flow-node` (on openregister `development`). This document says what OpenCatalogi still builds on top of it.

## D1. What OpenRegister owns, and what stays here

| Part | Owner | Where |
|---|---|---|
| Feed settings | OpenRegister `Source` (`application`, `type`, `config`, `mappingId`, `targetRegister`, `targetSchema`, `schedule`, `runAs`, `syncEnabled`, `protectedFields`, `provenance`, `identityProperty`, `deleteStrategy`, `conflictStrategy`, `maxItemsPerRun`, `lastSync*`) | openregister D-4, REQ-HAF-003 |
| Item tracking, change detection, collisions, tombstones | OpenRegister `SyncRecord` and `HarvestPipelineService` | openregister D-7, D-8, D-10, REQ-HAF-006, REQ-HAF-007, REQ-HAF-010 |
| Schedule, Run now, acting identity, no overlap | One flow per scheduled source with `openregister.trigger-schedule`, `openregister.trigger-manual` and node `openregister.harvest-source`; `POST /api/sources/{id}/sync` starts the manual trigger | openregister D-5, D-6, REQ-HAF-004, REQ-HAF-005 |
| Run accounting | The flow run; the node's output is the summary (counts, status, `complete`, errors) | openregister D-10 |
| Outbound guard, timeouts, retry, body cap | `HarvestHttpClient` with `OutboundUrlGuard` | openregister D-11, REQ-HAF-011 |
| **DCAT JSON-LD fetcher** | **OpenCatalogi** | D2 |
| **Feed cards and feed modal** | **OpenCatalogi** | D4, D7 |
| **Draft-only and provenance settings on every OpenCatalogi source** | **OpenCatalogi** (values), OpenRegister (enforcement) | D3 |

OpenCatalogi ships no harvest schema, no flow node, no flow writer, no scheduler, no checksum and no URL guard for harvesting (ADR-022, ADR-065).

## D2. The fetcher

`lib/Harvest/DcatJsonLdFetcher.php` implements OpenRegister's `IBatchSourceFetcher`. `lib/Listener/RegisterSourceFetchersListener.php` adds it on `RegisterSourceFetchersEvent`, registered in `lib/AppInfo/Application.php`.

- `getType()`: `opencatalogi.dcat-jsonld`. `getDisplayName()`: "DCAT catalogue (JSON-LD)".
- `getConfigSchema()`: `sourceUrl` (uri) or `sourceSlug` (string), exactly one (`oneOf`); `targetCatalog` (catalogue uuid, required). OpenRegister refuses a save whose `config` fails it with 422 naming the field.
- `gatherItems()`: fetch `sourceUrl` through the `HarvestHttpClient` OpenRegister hands in. Datasets are the nodes typed `dcat:Dataset`, found under the catalogue's `dcat:dataset` or in `@graph`. Key is the dataset's `@id`, value is the dataset node. A dataset without `@id` goes into `errors` with a null id. `complete` is true when the document was read and parsed whole; a fetch or parse failure returns `complete: false` and no items, so OpenRegister tombstones nothing.
- `sourceSlug`: when integriq is installed, the fetcher calls integriq's source abstraction for the body (the existing `openconnector.source-call` path; the call, its arguments and return keys are named at build time with a contract test on each side). When integriq is not installed the fetcher returns `complete: false` with the error "integriq is not installed, so a source slug cannot be read". A feed with `sourceUrl` never needs integriq.

## D3. Source settings OpenCatalogi writes

`lib/Service/Harvest/HarvestFeedService.php` writes every OpenCatalogi source through OpenRegister's `SourceService` (never the mapper) with:

| Field | Value | Why |
|---|---|---|
| `application` | `opencatalogi` | Ownership; the cards list only these. |
| `type` | `opencatalogi.dcat-jsonld` | The fetcher. |
| `targetRegister`, `targetSchema` | the register and schema of `config.targetCatalog`; the schema the administrator picks must be one of the catalogue's schemas, else 422 naming `targetSchema` | A catalogue can hold several schemas; OpenRegister does not know catalogues. This check is the reason the service exists. |
| `protectedFields` | `["publicationDate", "depublicationDate", "status"]`, plus `unlisted` once `publication-lifecycle-on-or` adds it | Draft-only. A publication is public when `publicationDate` is in the past (`publication_register.json`, `authorization.read`). OpenRegister D-12 lists `publicatiedatum` and `unlisted`; the schema names are `publicationDate` and `depublicationDate`, and `unlisted` does not exist yet. |
| `provenance` | `{"externalIdProperty": "source", "sourceProperty": "derivedFrom"}` | D5. |
| `identityProperty` | `source` | A local publication that already carries the dataset's `@id` is a `pre-existing-claim` collision. |
| `deleteStrategy` | `flag` | A dataset that leaves the feed is flagged, never deleted. |
| `conflictStrategy` | `manual` | The default until `harvest-conflict-policies` lets the administrator choose. |
| `runAs` | required when `schedule` is set; the form offers the current administrator visibly | OpenRegister refuses a schedule without one (REQ-HAF-003). |

The service is admin only and is not a pass-through: it adds the catalogue check and the fixed fields above. The feed list reads OpenRegister's sources filtered on `application: opencatalogi` through the same service.

## D4. Run now and Switch off

- Run now calls `POST /apps/openregister/api/sources/{id}/sync`, which starts the source flow's manual trigger and returns the run id (REQ-HAF-005). There is no OpenCatalogi run path.
- A second Run now while a run holds the lock ends `skipped` with reason `already-running` (REQ-HAF-004); the card shows that reason, it does not hide it.
- Switch off saves `syncEnabled: false`, which disables the flow. Delete removes the source and its flow; sync records stay as history.

## D5. Provenance on the publication

The publication schema gets two properties in a register fragment `lib/Settings/register.d/harvest-provenance.json` (ADR-037): `source` (string, format uri, title "Source", the harvested dataset's `@id`, written as `dct:source` in the outbound DCAT feed) and `derivedFrom` (string, title "Harvested from", the OpenRegister source uuid, `prov:wasDerivedFrom`). OpenRegister sets both after mapping, so a mapping cannot override them (REQ-HAF-008). The publication detail page shows "Harvested from <feed name>" with a link to the source when `derivedFrom` is set.

## D6. Mapping

A feed names an OpenRegister mapping (`mappingId`). The app seeds one, `dcat-dataset-to-publication`, that maps `dct:title`, `dct:description`, `dcat:keyword`, `dct:modified` and `dcat:theme` onto the publication. It writes none of the protected fields. Administrators edit or replace it with OpenRegister's mapping editor.

## D7. Screen

Board `OcInstellingen` on the Zuiddrecht canvas (5NkFW28vZUUij43xzxHg5a) has no harvest feed section yet. The pattern to follow is its "GitHub harvest" card: title, one line of what it does, "Source", "Last run" with date, time and count found, then "Run now", "Switch off" and a link out ("Open the source in OpenRegister"). The "Harvest feeds" section puts one such card per feed after the GitHub harvest card, plus "New", as the board's "Open data feeds" section does. "Last run" reads the source's `lastSyncDate` and `lastSyncStatus`, and the count found from the latest run summary of its flow. The feed form is `src/modals/HarvestFeedModal.vue` (ADR-004, `NcSelect` with `inputLabel`). A board for the section is still to be drawn.

## D8. Tests

- Unit: `DcatJsonLdFetcherTest` (datasets under `dcat:dataset` and under `@graph`, a dataset without `@id`, a parse error gives `complete: false`, the slug path with and without integriq), `HarvestFeedServiceTest` (fixed fields written, schema outside the catalogue refused, non-admin refused), `RegisterSourceFetchersListenerTest` against the real event class.
- Fixtures: `tests/fixtures/harvest/dcat-catalog.jsonld` (three datasets) and `dcat-catalog-v2.jsonld` (one changed, one removed, one without `@id`).
- e2e: `tests/e2e/harvest-feed.spec.ts` registers a feed against the fixture served by the test web server, clicks Run now, and checks the draft publication, its source and the count on the card.

## D9. Open points

- The feed list filters sources on `application`. OpenRegister's change does not name a filter on `GET /api/sources`; `HarvestFeedService` filters in PHP until it does.
- `publication-lifecycle-on-or` makes `draft` a stored state with initial `draft`. Until it lands a harvested publication's `status` takes the schema default `published`, and it stays private only because `publicationDate` is empty. Both readings keep it a draft; the e2e asserts on public visibility, not on `status`.
