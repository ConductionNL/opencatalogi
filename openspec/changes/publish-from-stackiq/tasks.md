# publish-from-stackiq tasks

## 1. Catalogue

- [ ] 1.1 `lib/Settings/register.d/publish-from-stackiq.json` seeds `applicatielandschap` by slug, unpublished.
- [ ] 1.2 The backfill publishes it once its scope resolves completely.

## 2. Scope resolution

- [ ] 2.1 `CatalogScopeSlugResolver::resolveScope()` and `schemaSlugMap()`: schema slugs resolve inside the catalogue's registers; pending list.
- [ ] 2.2 `SettingsService::backfillCatalogScopes()` uses it, reads catalogues past RBAC and writes `catalog_scope_pending`.
- [ ] 2.3 `CatalogiService::computeRewrittenRegistersAndSchemas()` uses the scoped map.
- [ ] 2.4 `CatalogScopePendingListener` on `RegisterCreatedEvent` and `RegisterUpdatedEvent`, registered in `Application`.

## 3. Tests

- [ ] 3.1 `tests/Unit/Service/CatalogScopeSlugResolverTest.php`: scoped resolution, a same-slug schema in another register is not taken, pending list.
- [ ] 3.2 `tests/Unit/Service/CatalogiServiceTest.php`: the pre-save rewrite is scoped.
- [ ] 3.3 `tests/Unit/Listener/CatalogScopePendingListenerTest.php`: runs only for a pending register.
- [ ] 3.4 `tests/Unit/Settings/PublishFromStackiqSeedTest.php`: the seed names stackiq's real slugs, ships unpublished, and names none of the never-public schemas.

## 4. Documentation

- [ ] 4.1 Manual page `docs/handleidingen/Applicatielandschap.md`.

## 5. Live on :8096

- [ ] 5.1 OpenCatalogi without stackiq: the catalogue exists, unpublished, scope on slugs, anonymous search returns nothing from it.
- [ ] 5.2 stackiq enabled after OpenCatalogi: the scope resolves to stackiq's ids without a reimport of OpenCatalogi.
- [ ] 5.3 Published stackiq objects appear in OpenCatalogi search and in the anonymous public API; unpublished ones do not.
- [ ] 5.4 With stackiq's property rules in place, excluded fields are absent from the anonymous API, search results and facets.
- [ ] 5.5 `catalogContract`, `contactPerson` and `aiSystem` objects never appear anonymously.

## 6. Waiting on others

- [ ] 6.1 stackiq ships the property-level read rules listed in `design.md` (lane sq).
- [ ] 6.2 stackiq adds `usage.publicationDate` and the public read rule on it (lane sq, `sharing-itsm-exchange`).
