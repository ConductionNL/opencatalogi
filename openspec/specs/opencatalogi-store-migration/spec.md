# Capability: opencatalogi-store-migration

## Purpose

Migrate the opencatalogi `useObjectStore` onto the canonical `createObjectStore`
factory from `@conduction/nextcloud-vue` while preserving the existing public API
so that no Vue file requires modification.

## Requirements

### Requirement: Outer store wraps the canonical lib store (REQ-OSM-1)
The opencatalogi `useObjectStore` (Pinia id `'object'`) **MUST** delegate its CRUD operations to an inner store created via `@conduction/nextcloud-vue`'s `createObjectStore`. The inner store **MUST** use a distinct Pinia id so both stores can coexist on the same Pinia instance.

#### Scenario: Inner store id is distinct
- **GIVEN** the outer Pinia store named `'object'`
- **WHEN** the inner lib store is instantiated
- **THEN** its Pinia id **MUST** be `'opencatalogi-objects-inner'`

#### Scenario: CRUD delegation
- **GIVEN** a registered object type
- **WHEN** any of `fetchCollection`, `fetchObject`, `saveObject`, `deleteObject`, `resolveReferences` is called on the outer store
- **THEN** the call **MUST** be forwarded to the inner store
- **AND** the result **MUST** be reshaped to match the existing public API (especially `getCollection(type)` returning `{ results: [...] }`)

### Requirement: Plugin set (REQ-OSM-2)
The inner store **MUST** be constructed with the following plugins from the lib:
1. `filesPlugin`
2. `auditTrailsPlugin`
3. `relationsPlugin`
4. `lifecyclePlugin`
5. `selectionPlugin`
6. `liveUpdatesPlugin`

#### Scenario: All 6 plugins are mounted
- **GIVEN** the inner store factory call
- **WHEN** the `plugins:` array is inspected
- **THEN** all 6 plugin functions **MUST** be present and invoked (each is a function returning a plugin descriptor)

### Requirement: Public API preservation (REQ-OSM-3)
The outer store **MUST** preserve every existing public method, getter, and state-shape so that no Vue file requires modification.

#### Scenario: getCollection shape preserved
- **GIVEN** any registered type with at least one fetched record
- **WHEN** `objectStore.getCollection(type)` is read
- **THEN** the return **MUST** be `{ results: Array<any> }`, not a bare array

#### Scenario: Pagination shape preserved
- **GIVEN** a fetched collection
- **WHEN** `objectStore.getPagination(type)` is read
- **THEN** the return **MUST** include the keys `total`, `page`, `pages`, `limit`, `next`, `prev`

#### Scenario: All public methods remain callable
- **GIVEN** the outer store instance
- **WHEN** the consumer calls any of `setActiveObject`, `getActiveObject`, `clearActiveObject`, `fetchRelatedData`, `fetchSettings`, `getSchemaConfig`, `createObject`, `updateObject`, `saveObject`, `deleteObject`, `publishObject`, `depublishObject`, `validateObject`, `lockObject`, `unlockObject`, `setSearchTerm`, `clearSearchTerm`, `loadMore`, `loadPrevious`, `preloadCollections`, `copyObject`, `setSelectedObjects`, `setSelectedAttachments`, `setObjectError`, `clearObjectError`, `clearAllObjectErrors`, `getObjectError`, `toggleSelectAllObjects`, `updateColumnFilter`, `initializeProperties`, `initializeColumnFilters`, `massDeleteObjects`, `massPublishObjects`, `massDepublishObjects`, `massValidateObjects`, `massLockObjects`, `massUnlockObjects`, `refreshActivePublicationFiles`, `publishAttachment`, `depublishAttachment`, `massPublishAttachments`, `massDepublishAttachments`, `registerObjectType`, `unregisterObjectType`, `fetchSchema`, `fetchObject`, `fetchCollection`, `setCollection`, `setLoading`, `setError`, `setPagination`, `setState`
- **THEN** the call **MUST** resolve (not throw `is not a function`)

### Requirement: Local-only modules untouched (REQ-OSM-4)
The migration **MUST NOT** modify `catalog.js`, `navigation.ts`, `search.ts`, or any Vue file.

#### Scenario: catalog.js calls the same `/api/{catalogSlug}` endpoint
- **GIVEN** the catalog store is constructed
- **WHEN** `fetchPublications` is invoked
- **THEN** the URL **MUST** still target `/index.php/apps/opencatalogi/api/{catalogSlug}/...`

#### Scenario: No Vue file diff
- **GIVEN** the migration commit
- **WHEN** the diff against `origin/development` is inspected
- **THEN** no file under `src/views/`, `src/components/`, `src/modals/`, `src/dialogs/`, or `src/sidebars/` **SHOULD** appear in the diff

### Requirement: Plugin override side-effects (REQ-OSM-5)
Methods previously implemented locally (`publishObject`, `depublishObject`, `lockObject`, `unlockObject`) keep their object-shaped signature `(objectItem)` even though `lifecyclePlugin` expects `(type, id, options)`. The wrapper **MUST** translate between the two shapes.

#### Scenario: publishObject(objectItem) succeeds
- **GIVEN** an object with `'@self'.register` and `'@self'.schema` and an `id`
- **WHEN** `objectStore.publishObject(objectItem)` is called
- **THEN** the wrapper **MUST** extract type/register/schema and dispatch to the underlying publish endpoint
- **AND** the active publication **MUST** be updated when its id matches

### Requirement: Cross-schema createObject override (REQ-OSM-6)
`createObject(type, data, publicationData)` **MUST** accept an optional `publicationData = { register, schema }` override and route the POST to the override URL instead of the type's default config.

#### Scenario: copyObject preserves source schema
- **GIVEN** a publication object whose `'@self'.schema` differs from the publication type's default schema
- **WHEN** `objectStore.copyObject('publication', sourceId)` is called
- **THEN** the new object **MUST** be created against `'@self'.register / '@self'.schema` from the source, not the type's default
