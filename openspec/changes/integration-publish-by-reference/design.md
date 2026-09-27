# Design: integration-publish-by-reference

Read at opencatalogi development `1694b051`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| publication page | `src/manifest.json` page `PublicationDetail`: `pub-files` (OpenRegister files integration), `pub-links` (bookmarks integration) | files are stored copies; bookmarks are a user's Nextcloud Bookmarks, not public documents |
| sitemap | `lib/Service/SitemapService.php:297` `buildSitemap()` maps each file with a `downloadUrl` (:338-350) through `mapDiwooDocument()` (:508), `loc` = `downloadUrl` (:513) | files only |
| DCAT | `lib/Service/DcatMappingService.php:356` `mapDistribution(array $file, ...)`, called at :268 | takes a formatFiles-shape entry; `downloadURL` and `accessURL` (:369-370) |
| public API | `lib/Controller/PublicationsController.php:896` `attachments()` via `PublicationService::attachments()` | files only |
| outbound guard | `lib/Service/DirectoryService.php:1845` `validateOutboundUrl()` | blocks loopback, private and link-local ranges |
| background jobs | `appinfo/info.xml:136-149` | no reference check |

## D1. A reference is an object, not a file

A new schema `documentReference` in `lib/Settings/register.d/publication-document-references.json` (publication register): `publication` ($ref publication), `url` (format uri, https only), `title`, `format` (a file extension from the EU file-type list, used for `diwoo:format` and `dcat:mediaType`), `sourceSystem` (free text, for example the DMS name), `publicationDate`, `depublicationDate`, `lastCheckedAt`, `reachable` (boolean), `lastStatus`. Stored in OpenRegister (ADR-022, ADR-070). A reference is public only inside its window, evaluated by OpenRegister's published predicate the way a file window is.

## D2. One shape for files and references at the mapping seam

`SitemapService::buildSitemap()` and `DcatMappingService` get a small adapter, `DocumentReferenceMapper::asFileEntry(array $reference)`, that returns the formatFiles shape the mappers already take: `downloadUrl` = `accessUrl` = the reference URL, `extension` = `format`, `title`, `published` = `publicationDate`, `modified` = the reference's `@self.updated`. The mappers stay as they are; the callers append references to the file list. A reference with `reachable` false stays listed (the harvester decides), and is reported (D4).

`PublicationService::attachments()` returns references in the same list, each with `kind: reference`, so the public API and portaliq's reading room show them beside files.

## D3. Adding a reference

The publication page gets a `pub-references` widget: an `object-table` over `documentReference` filtered on `publication = @objectId`, with the standard add, edit and delete actions (declarative, no custom component). The form validates the URL on save through `validateOutboundUrl()`, so a private address is refused with a reason.

## D4. A daily reachability check

A `TimedJob` `DocumentReferenceCheck` (daily, ADR-069) sends a `HEAD` request (falling back to a ranged `GET`) to each reference inside its window, through the same guard, with a short timeout and a per-run cap. It writes `lastCheckedAt`, `reachable` and `lastStatus`. The DiWoo validator report (WOO-TOOI-004) lists unreachable references per catalogue.

## Declarative or imperative

- `pub-references` is a declarative `object-table` widget.
- An `x-openregister-aggregations` entry counts unreachable references per publication, shown on the widget header.
- The reachability check is imperative scheduled work (ADR-031 exception).

## Seed data

One reference on the first seed publication in `lib/Settings/publication_register.json`, pointing at a public page of a fictional municipality's document system (`https://documenten.voorbeeldgemeente.nl/besluit-2026-001.pdf`), format `pdf`, window open.

## Risks

- A source system that answers `HEAD` with 405. The check falls back to a ranged `GET` of one byte.
- A source system that moves its URLs. The check marks them, but fixing is the editor's work; D4 only makes it visible.
