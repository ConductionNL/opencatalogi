---
retrofit_extensions:
  - CMS-036
  - CMS-037
  - CMS-038
  - CMS-039
  - CMS-040
---

# Content Management

## ADDED Requirements

### Requirement: Page management UI with embedded content blocks (CMS-036)
The system SHALL provide a page management frontend comprising a `ViewPageModal` (read a
page and its content blocks) and a `PageContentForm` modal for adding/editing a content
block. Content blocks are stored as nested data on the parent `page` object: saving a
block persists the whole page via `objectStore.updateObject('page', id, page)`, and
`DeletePageContentDialog` removes a block by updating the page object with the block
removed. Modals/dialogs are toggled through the navigation store (`page` modal,
`deletePageContent` dialog).

**Priority:** Should **Status:** Implemented

#### Scenario: Add or edit a page content block
- GIVEN the page content form is open for a page
- WHEN the user saves the content block
- THEN the parent page MUST be persisted via `objectStore.updateObject('page', id, page)`

#### Scenario: Delete a page content block
- GIVEN a content block on a page
- WHEN the delete-page-content dialog confirms removal
- THEN the page MUST be updated with the block removed via `updateObject('page', ...)`

### Requirement: Menu management UI with embedded menu items (CMS-037)
The system SHALL provide a menu management frontend comprising a `ViewMenuModal` (read a
menu and its items), a `MenuItemForm` modal for adding/editing items, a
`DeleteMenuItemModal`, and a `CopyMenuDialog`. Menu items are stored as nested data on the
parent `menu` object: saving or deleting an item persists the whole menu via
`objectStore.updateObject('menu', id, menu)`. Copy-menu clones the active menu with a
`(kopie)` title via `objectStore.createObject('menu', clone)`. Modals/dialogs are toggled
through the navigation store.

**Priority:** Should **Status:** Implemented

#### Scenario: Add or edit a menu item
- GIVEN the menu item form is open for a menu
- WHEN the user saves the item
- THEN the parent menu MUST be persisted via `objectStore.updateObject('menu', id, menu)`

#### Scenario: Copy a menu
- GIVEN an active menu
- WHEN the copy-menu dialog is confirmed
- THEN a new menu MUST be created via `objectStore.createObject('menu', clone)` with a `(kopie)` title

### Requirement: Theme management UI (CMS-038)
The system SHALL provide a theme management frontend comprising a `ViewThemeModal` (read a
theme), an `AddPublicationThemeModal` that attaches a theme to a publication by updating
the publication via `objectStore.updateObject('publication', id, updatedPublication)`, and
a `DeleteMultipleThemesDialog` that bulk-deletes selected themes via repeated
`objectStore.deleteObject('theme', id)`. Modals/dialogs are toggled through the navigation
store.

**Priority:** Should **Status:** Implemented

#### Scenario: Attach a theme to a publication
- GIVEN the add-publication-theme modal is open
- WHEN the user confirms the theme selection
- THEN the publication MUST be updated via `objectStore.updateObject('publication', id, updatedPublication)`

#### Scenario: Bulk-delete themes
- GIVEN multiple themes are selected
- WHEN the delete-multiple-themes dialog is confirmed
- THEN each selected theme MUST be removed via `objectStore.deleteObject('theme', id)`

### Requirement: Glossary view UI (CMS-039)
The system SHALL provide a `ViewGlossaryModal` that reads and displays a glossary term
from the object store, toggled through the navigation store.

**Priority:** Should **Status:** Implemented

#### Scenario: View a glossary term
- GIVEN a glossary term is the active object
- WHEN the navigation store modal is set to the glossary modal
- THEN the term's details MUST be rendered read-only

### Requirement: Content-management presentation helpers (CMS-040)
The system SHALL provide frontend helper services for content presentation: `getTheme()`
returns `'light'` or `'dark'` by reading the document body's `data-theme-light` /
`data-theme-default` attributes (honouring `prefers-color-scheme` for the default theme,
defaulting to `'dark'`), and `getPublicationTypeId(url)` extracts the trailing path segment
of a publication-type URL as its id.

**Priority:** Should **Status:** Implemented

#### Scenario: Resolve the active Nextcloud theme
- GIVEN the body carries `data-theme-light`
- WHEN `getTheme()` is called
- THEN it MUST return `'light'`

#### Scenario: Default theme follows the OS color scheme
- GIVEN the body carries `data-theme-default`
- WHEN `getTheme()` is called
- THEN it MUST return `'light'` if `prefers-color-scheme: light` matches, otherwise `'dark'`

#### Scenario: Extract a publication type id from a URL
- GIVEN a publication-type URL ending in `/42`
- WHEN `getPublicationTypeId(url)` is called
- THEN it MUST return `42`
