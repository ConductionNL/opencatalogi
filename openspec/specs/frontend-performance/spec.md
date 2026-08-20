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

**Priority:** Should **Status:** Implemented

#### Scenario: Single-use cloneDeep call sites use structuredClone, not lodash
- GIVEN `ObjectModal.vue`, `ViewMenuModal.vue`, `MenuItemForm.vue`, and `DeletePageContentDialog.vue` each use exactly one lodash function (`cloneDeep`) to clone a plain JSON-shaped modal/form-state object
- WHEN the file is inspected
- THEN it MUST NOT import `lodash` at all
- AND the clone call site MUST use `structuredClone(...)` (or an equivalent local zero-dependency helper)

#### Scenario: Multi-function call site cherry-picks named lodash modules
- GIVEN `PageContentForm.vue` uses two lodash functions (`cloneDeep`, `upperFirst`)
- WHEN the file is inspected
- THEN it MUST import each function from its own module path (`lodash/cloneDeep`, `lodash/upperFirst`)
- AND MUST NOT import the `lodash` barrel

#### Scenario: Production build no longer bundles the full lodash graph in these chunks
- GIVEN a production `npm run build`
- WHEN the chunks containing the five affected files are inspected
- THEN they MUST NOT include lodash modules beyond the ones actually cherry-picked (`cloneDeep`, `upperFirst`)
