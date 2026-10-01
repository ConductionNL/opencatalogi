# Design: publish-from-stackiq

## One mechanism, extended

`publiccode-github-harvest` seeds catalogue `componenten` with its scope by slug and resolves it in `SettingsService::backfillCatalogScopes()` through `CatalogScopeSlugResolver::resolve()`. This change reuses that path and adds no second one:

- `CatalogScopeSlugResolver::resolveScope()` resolves registers with `resolve()`, then resolves schemas with `resolve()` and a lookup scoped to the resolved registers. It returns the scope, whether it changed, and what is still pending.
- `CatalogiService::computeRewrittenRegistersAndSchemas()`, which the pre-save catalogue listener calls, uses the same scoped map (`CatalogScopeSlugResolver::schemaSlugMap()`). Its contract stays: an unresolvable slug throws, the listener logs it and saves the payload unchanged, and the backfill resolves it later.

## Why the schema lookup is scoped

`SchemaMapper::find('module')` matches every schema with that slug and picks one by ownership, then id. Since OpenRegister widened the schema unique key to organisation, application and slug, two apps can each own `module`. On an instance that upgraded from an older OpenCatalogi, OpenCatalogi's own retired `organization` schema still exists beside stackiq's.

`PublicationService::getCatalogFilters()` unions the registers and schemas of every catalogue. If a stackiq catalogue resolved `organization` to OpenCatalogi's schema, the union would pair register `publication` with that schema and publish OpenCatalogi's organisation records. Scoping the lookup to the catalogue's registers rules that out. With no numeric register, `computeRewrittenRegistersAndSchemas()` keeps the old global lookup, because there is nothing to scope to and that is the existing behaviour for hand-made catalogues.

## stackiq installed after OpenCatalogi

The backfill writes app config `catalog_scope_pending`: a JSON list of register slugs still unresolved, plus the id of each resolved register whose catalogue still names a schema by slug. `CatalogScopePendingListener` listens to OpenRegister's `RegisterCreatedEvent` and `RegisterUpdatedEvent`. For every other register it costs one config read. When the event's register slug or id is pending, it runs the backfill.

OpenRegister's import creates the register first and updates it after linking the schemas, so the first event can resolve the register and the update resolves the schemas. A static flag stops a backfill from re-entering itself.

The backfill now reads catalogues with `_rbac: false` and `_multitenancy: false`. It runs from the import and from register events, often without a user, and the seeded catalogue is unpublished, so an RBAC-filtered read would never see it.

## Inert without stackiq

The seed is created in register `publication`, which always exists. Its scope stays `["stackiq"]` and the six schema slugs. `PublicationService` reads scope entries with `intval` (a slug becomes 0) and `applySchemaScopeReadRuleGuard()` drops non-numeric schemas for anonymous callers, so nothing is published. The catalogue has no `published` date, so the catalogue read rule (`published <= now`) hides it from anonymous visitors.

## Published once resolved

The catalogue form has no publication date field, so "an administrator publishes it" would need the API. Instead the backfill sets `published` to now when, in one run, it resolved slugs and nothing is left on a slug, the catalogue has no `published` yet, and its slug is in `SettingsService::PUBLISH_WHEN_RESOLVED` (only `applicatielandschap`). It never touches `published` again, and never on a catalogue made in the form (those store ids, so nothing resolves). Publishing the catalogue adds no new disclosure: an anonymous visitor can already read every stackiq object it shows through OpenRegister's public API. To stop showing the landscape, remove the catalogue or its schemas.

## What is public

OpenCatalogi's search, its public API and its facets go through OpenRegister `searchObjectsPaginated(_rbac: true)`:

- Object level: stackiq's schema read rules. `module`, `catalogService` and `connection` are public when `publicationDate <= now`; `module` also when `registeredBy` is `Supplier`. `moduleVersion` and `suite` are public. `usage` has no public rule until stackiq adds one on `usage.publicationDate`.
- Field level: `PropertyRbacHandler::filterReadableProperties()` strips a property whose `authorization.read` the caller does not meet, in `RenderObject`. `MagicFacetHandler` and `AggregateVisibility` withhold a facet over such a property. Admins bypass both, so every check of this change runs anonymously.

A rule of `read: ["authenticated"]` keeps a field for every signed-in user and strips it for anonymous visitors. Every stackiq writer is signed in, so no writer loses a field it cannot read (the or#4170 erase case does not arise).

The fields come from lane sq's answer. stackiq gives each of these `authorization.read: ["authenticated"]`:

| schema | fields hidden from anonymous visitors |
|---|---|
| module | contactPerson, usages, dpiaDocumentRef, verwerkingsregisterRef |
| moduleVersion | usages (same reason as module: it says who uses the version) |
| suite | contactPerson |
| catalogService | contactPerson |
| connection | longDescription, dateInDevelopment, dateInUse, dateEndSupport, dateWithdrawn, nonMunicipalProvision, realisedWithIntermediaryModule, provider, service, registeredBy, serviceDeskSystem, serviceDeskRecordId, serviceDeskUrl, serviceDeskSyncedAt |
| usage | provider, contactPerson, businessOwner, technicalOwner, participants, startDateAcquisition, startDatePlanned, startDateOutPhasing, startDateOutPhased, amefElements, elementRef, plannedReplacement, plannedReplacementDate, timeClassification, timeRationale, timeReviewDate, installedVersion, serviceDeskSystem, serviceDeskRecordId, serviceDeskUrl, serviceDeskSyncedAt, and every value assessment, cost and licence field |

`usage.interneAnnotation` already carries its own rule and must keep it: a fragment appends to a list, so adding `authenticated` there would widen it. `publicationDate` stays readable on every schema; the object read rules match on it.

## Alternatives considered

- **A field filter on the catalogue.** OpenCatalogi would hide fields, OpenRegister's own public object API would keep serving them. Rejected.
- **OpenCatalogi writing property rules onto stackiq's schemas.** Another app's schemas, overwritten again by stackiq's next import. Rejected.
- **Creating the catalogue in code only when stackiq is installed.** A second mechanism beside the seeded `componenten` catalogue. Rejected.
