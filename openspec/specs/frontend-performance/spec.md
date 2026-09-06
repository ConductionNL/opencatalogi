---
status: done
retrofit_extensions:
  - FEP-001
---

# Frontend Performance

Bundle-size hygiene requirements for the opencatalogi SPA. This capability was created by
`tree-shake-lodash-cherry-picks` — bundle-size hygiene had no prior home in opencatalogi's
spec tree.

## Requirements

### Requirement: No lodash barrel imports for single-function use (FEP-001)

Source files that need at most a small, fixed set of `lodash` helper functions MUST import
those functions via lodash's per-function module path (e.g. `lodash/cloneDeep`) rather than the
package barrel (`import _ from 'lodash'`), so webpack can tree-shake the unused remainder of the
library. Files that clone plain JSON-serializable state (no functions, no DOM nodes, no circular
references) SHOULD prefer the native `structuredClone()` over any lodash helper at all.

Retiring the CMS surfaces (`opencatalogi-has-no-cms`) removed the four files this
requirement originally named besides `ObjectModal.vue`: `ViewMenuModal.vue`,
`MenuItemForm.vue` and `DeletePageContentDialog.vue` were the other single-clone call
sites, and `PageContentForm.vue` was the only file that cherry-picked two functions.
Nothing in `src/` imports lodash any more, so the requirement now holds over the whole
source tree rather than over a named list, and the package is no longer a dependency.

**Priority:** Should **Status:** Implemented

#### Scenario: No file in the source tree imports lodash
- GIVEN every `.vue`, `.js` and `.ts` file under `src/`
- WHEN the file is inspected
- THEN it MUST NOT import or require `lodash`, by barrel (`from 'lodash'`) or by per-function path (`from 'lodash/cloneDeep'`)

#### Scenario: The surviving clone call site clones natively
- GIVEN `ObjectModal.vue` clones a plain JSON-shaped modal-state object
- WHEN the file is inspected
- THEN the clone call site MUST use `structuredClone(...)`

#### Scenario: lodash is not a declared dependency
- GIVEN nothing in `src/` imports it
- WHEN `package.json` is inspected
- THEN neither `lodash` nor `@types/lodash` MUST appear in `dependencies` or `devDependencies`

#### Scenario: No lodash reaches the bundle through this app's own imports
- GIVEN a production `npm run build`
- WHEN the emitted chunks are inspected
- THEN no lodash module MUST be reachable from a file under `src/`
- AND a lodash module reached only through a shared dependency (`@conduction/nextcloud-vue` declares it) is outside this requirement, which governs this repository's imports
