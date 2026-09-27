# Design: woo-metadata-suggestions

Read at opencatalogi development `1694b051`.

## Where it lands

| piece | file | what is there now |
|---|---|---|
| Woo fields read at sitemap time | `lib/Service/SitemapService.php:508` `mapDiwooDocument()`: category from `category`, `tooiCategorieUri` or `tooiCategorieNaam` (:552), `soortHandeling` (:573), publisher from `resolveOrganisationTooiIdentifier()` (:639) | an unresolved axis is left out and reported by the validator (`collectDiwooViolations()` :713) |
| value lists | `lib/Service/TooiVocabularyService.php`: `resolveInformatiecategorie()` :138, `resolveSoortHandeling()` :168 with `DEFAULT_SOORTHANDELING = 'ontvangst'` :123, `resolveOrganisatie()` :197 | the only allowed values |
| new records | `lib/Listener/ObjectCreatedEventListener.php:89` `handle()` calls `EventService::handleObjectCreateEvents()` (:119) | auto-publishing only |
| publication page | `src/manifest.json` page `PublicationDetail` (type `detail`, widgets `pub-data`, `pub-files`, ...) | no suggestion surface |
| detail widgets | `src/main.js:80-125` registers custom detail widgets through `registerDashboardWidget()` | the only path `CnWidgetGrid` resolves a `widgetKey` through (see the note in `src/registry.js:80-88`) |

## D1. A suggestion is an object

A new schema `metadataSuggestion` in `lib/Settings/register.d/woo-metadata-suggestions.json` (publication register): `publication` ($ref publication), `field` (enum `category`, `soortHandeling`, `organization`, `title`, `summary`), `value`, `valueLabel`, `source` (enum `rules`, `hermiq`), `reason`, `status` (enum `pending`, `accepted`, `rejected`), `decidedBy`, `decidedAt`. OpenRegister stores it and keeps the audit trail (ADR-022, ADR-070). A suggestion is only created for a field the publication does not have, and never twice for the same field and value.

## D2. The rules floor

`WooMetadataSuggestionService::suggestFromRules(array $publication)`:

- `category`: the `WooCategoryMapping` row for the publication's schema slug when that schema exists (open change `woo-category-mapping-intake`), else the category of the Woo catalogue schema the publication sits in (`SitemapService::INFO_CAT` maps category codes to schema names).
- `organization`: the organisation of the catalogue when the publication has none.
- `soortHandeling`: `ontvangst` for a record created by an integriq synchronisation, `vaststelling` for one handed over by a case app. The value must resolve through `resolveSoortHandeling()`.

Every value is checked against `TooiVocabularyService` before it is stored. A value that does not resolve is not suggested.

It runs when a publication is created (a new listener on OpenRegister's `ObjectCreatedEvent`, queued, so the save does not wait) and on demand.

## D3. Hermiq adds, never replaces

`POST /api/publications/{id}/metadata-suggestions` with `source=hermiq` sends the document text of the publication's files (OpenRegister's extracted text) and the allowed values of each missing field to Hermiq's suggestion surface through a `HermiqMetadataClient`, resolved by class name the way `NationalIndexService` resolves the gateway. Returned values that do not resolve against the value lists are dropped. When Hermiq is not installed the endpoint answers 409 with `hermiq-unavailable` and the page hides the action.

## D4. A person decides

`POST /api/metadata-suggestions/{id}/accept` writes the value to the publication through `ObjectService::saveObject()` with RBAC on, then sets the suggestion to `accepted` with `decidedBy` and `decidedAt`. `.../reject` only sets `rejected`. Both refuse a user who cannot update the publication (ADR-005, fail closed), checked through OpenRegister's authorisation on the publication, not a new rule.

The publication page gets a `metadata-suggestions` widget (custom component, registered with `registerDashboardWidget()` in `src/main.js`) that lists pending suggestions with the value, the source and the reason, and an Accept and a Reject button each.

## Declarative or imperative

- The suggestion's lifecycle (`pending` to `accepted` or `rejected`) is declared as `x-openregister-lifecycle` on `metadataSuggestion`.
- The count of pending suggestions on a publication is an `x-openregister-aggregations` entry, read by the widget header.
- Generating suggestions is imperative: it reads value lists and, for Hermiq, calls another app (ADR-031 exceptions: external integration, domain rule selection).

## Seed data

One pending `rules` suggestion for the category of the second seed publication in `lib/Settings/publication_register.json`, so a fresh install shows the widget with something to accept.

## Risks

- A wrong rules suggestion accepted without reading. The widget shows the source and reason next to every value, and nothing is accepted in bulk.
- Document text sent to Hermiq. Hermiq runs on the same instance under its own model settings; the request carries no more than the files already published or about to be.
