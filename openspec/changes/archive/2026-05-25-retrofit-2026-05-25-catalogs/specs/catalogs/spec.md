---
retrofit_extensions:
  - CAT-013
  - CAT-014
  - CAT-015
  - CAT-016
---

# Catalogs

## ADDED Requirements

### Requirement: Catalog store fetches a catalog's publications and registers object types (CAT-013)
The frontend catalog store SHALL, when a catalog is set active, fetch that catalog's
publications via the public slug endpoint `GET /index.php/apps/opencatalogi/api/{slug}`
(falling back to the catalog id, then the last-used catalog id), with `_extend` of
`@self.schema,@self.register` and pagination. For each returned publication it resolves
the publication's schema/register references against the response's `@self.schemas` /
`@self.registers` maps and registers the schema slug as an object type in the shared
object store (once per slug). On error the publications collection is reset to empty.

**Priority:** Must **Status:** Implemented

#### Scenario: Set active catalog and load its publications
- GIVEN a catalog with a `slug`
- WHEN `catalogStore.setActiveCatalog(catalog)` is called
- THEN the store MUST fetch `GET /api/{slug}` with `_extend=@self.schema,@self.register`
- AND each publication's schema slug MUST be registered as an object type exactly once

#### Scenario: Fetch with no resolvable catalog id
- GIVEN no catalogId argument, no active catalog, and no last-used catalog id
- WHEN `catalogStore.fetchPublications()` is called
- THEN the store MUST log an error and return without issuing an HTTP request

### Requirement: Create and edit catalogs via the catalog modal (CAT-014)
The system SHALL provide a `CatalogModal` (shown when the navigation store modal is
`catalog`) for creating and editing a catalog. The modal validates the catalog against
the Catalogi entity, maps selected registers/schemas to their IDs and the selected
organization to its id, normalises the status to its id, and saves via
`objectStore.updateObject('catalog', id, item)` (edit) or
`objectStore.createObject('catalog', item)` (create), then closes after a short delay.

**Priority:** Must **Status:** Implemented

#### Scenario: Create a new catalog
- GIVEN the modal is open without an existing catalog id
- WHEN the user submits valid title, slug, and registers
- THEN the catalog item's id MUST be dropped and `objectStore.createObject('catalog', item)` called
- AND the modal MUST close after the success feedback delay

#### Scenario: Edit an existing catalog
- GIVEN the modal is open for a catalog with an id
- WHEN the user submits the form
- THEN `objectStore.updateObject('catalog', id, item)` MUST be called

### Requirement: View catalog details and detail page (CAT-015)
The system SHALL provide a `ViewCatalogi` modal and a `CatalogDetailPage` route view that
display a catalog read from the object store. The detail page resolves the catalog by the
route `id` param via `objectStore.fetchObject('catalog', id)`, supports navigating back to
the catalogs list and forward to the catalog's publications (by slug), and the view modal
presents catalog details across tabbed panels.

**Priority:** Should **Status:** Implemented

#### Scenario: Open a catalog detail page by route id
- GIVEN a route with an `id` param
- WHEN `CatalogDetailPage` mounts
- THEN it MUST call `objectStore.fetchObject('catalog', id)` and render the active catalog

#### Scenario: Navigate to a catalog's publications
- GIVEN a catalog with a `slug` on the detail page
- WHEN the user opens its publications
- THEN the router MUST push the `Publications` route with `catalogSlug` set to the slug

### Requirement: Catalogs dashboard widget (CAT-016)
The system SHALL provide a `CatalogiWidget` Nextcloud dashboard widget (registered as
`opencatalogi_catalogi_widget`) that on mount fetches the catalog collection via
`objectStore.fetchCollection('catalog')`, renders catalogs as widget items with a
theme-aware database icon, shows an empty state when there are none, and navigates to a
catalog's publications page when an item is clicked.

**Priority:** Should **Status:** Implemented

#### Scenario: Widget loads catalogs on mount
- GIVEN the dashboard renders the catalogs widget
- WHEN the widget mounts
- THEN it MUST call `objectStore.fetchCollection('catalog')`
- AND render an empty-content state if no catalogs are returned

#### Scenario: Click a catalog widget item
- GIVEN a catalog item shown in the widget
- WHEN the item is clicked
- THEN the browser MUST navigate to that catalog's publications URL
