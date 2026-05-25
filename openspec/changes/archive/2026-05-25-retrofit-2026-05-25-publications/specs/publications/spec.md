---
retrofit_extensions:
  - PUB-016
  - PUB-017
  - PUB-018
---

# Publications

## ADDED Requirements

### Requirement: Publish a publication object from the frontend store (PUB-016)
The frontend object store SHALL publish a publication by POSTing to the OpenRegister
publish endpoint `/index.php/apps/openregister/api/objects/{register}/{schema}/{id}/publish`.
The register and schema identifiers are resolved from the object's `@self` metadata
(falling back to top-level `register`/`schema`) and reduced to bare IDs via `extractId`.
On success the store replaces the active `publication` object with the server's response
and removes the object from the current multi-select selection. Per-object loading and
error state are tracked under the keys `publish_{id}`.

**Priority:** Must **Status:** Implemented

#### Scenario: Publish an unpublished publication
- GIVEN a publication object with resolvable `id`, `register`, and `schema`
- WHEN `objectStore.publishObject(object)` is called
- THEN a POST request MUST be sent to the OpenRegister `.../{id}/publish` endpoint
- AND the returned object MUST replace the active `publication` if it matches the object's id
- AND the object MUST be removed from the selected-objects list if currently selected

#### Scenario: Publish with missing register/schema metadata
- GIVEN a publication object lacking `id`, `register`, or `schema`
- WHEN `objectStore.publishObject(object)` is called
- THEN the store MUST throw an error before issuing any HTTP request

### Requirement: Depublish a publication object from the frontend store (PUB-017)
The frontend object store SHALL depublish a publication by POSTing to the OpenRegister
depublish endpoint `/index.php/apps/openregister/api/objects/{register}/{schema}/{id}/depublish`,
mirroring the publish flow: register/schema resolved from `@self`, active publication
replaced with the server response on success, the object removed from the current
selection, and loading/error state tracked under `depublish_{id}` keys.

**Priority:** Must **Status:** Implemented

#### Scenario: Depublish a published publication
- GIVEN a published publication object with resolvable `id`, `register`, and `schema`
- WHEN `objectStore.depublishObject(object)` is called
- THEN a POST request MUST be sent to the OpenRegister `.../{id}/depublish` endpoint
- AND the returned object MUST replace the active `publication` if it matches the object's id

#### Scenario: Depublish failure surfaces an error
- GIVEN the depublish endpoint returns a non-OK HTTP status
- WHEN `objectStore.depublishObject(object)` is called
- THEN the store MUST record the error under `depublish_{id}` and re-throw it

### Requirement: Provide a publish/depublish confirmation dialog (PUB-018)
The system SHALL provide a `PublishPublicationDialog` shown when the navigation store's
dialog is `publishPublication`. The dialog reads the active `publication` from the object
store, displays a "Publish publication" or "Depublish publication" heading based on the
publication's status, and renders a confirmation prompt with Publish/Cancel actions plus
success and error note cards.

**Priority:** Should **Status:** Implemented

#### Scenario: Open the publish dialog for an unpublished publication
- GIVEN the active publication has a status other than `Published`
- WHEN the navigation store dialog is set to `publishPublication`
- THEN the dialog MUST render with a "Publish publication" heading and the publication title
- AND a primary Publish button MUST be shown

#### Scenario: Open the dialog for a published publication
- GIVEN the active publication has status `Published`
- WHEN the dialog is opened
- THEN the dialog MUST render with a "Depublish publication" heading

> **Notes (observed-but-buggy — not fixed by this retrofit):**
> The dialog's confirm handler `handleCopy()` does NOT call `publishObject`/`depublishObject`.
> It reads the active **menu** object, clones it with a `(kopie)` title, and calls
> `objectStore.createObject('menu', ...)` — clearly copy-pasted from a "copy menu" dialog.
> So clicking Publish currently copies a menu instead of publishing the publication.
> Additionally, the `catch (error)` block shadows the outer `error` ref and then assigns
> `error.value`, which throws on the shadowed local. REQ PUB-018 specifies the *intended*
> publish/depublish confirmation behavior; the handler bug is tracked separately and must
> be fixed in a code change, not silently re-specified here.
