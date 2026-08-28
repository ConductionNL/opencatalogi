---
kind: code
depends_on: []
---

# Proposal: tree-shake-lodash-cherry-picks

## Why

Five source files pull in the entire `lodash` package via a barrel import —
`import _ from 'lodash'` — but between them use exactly two functions:

- `src/modals/object/ObjectModal.vue:226` — `_.cloneDeep` only (used once, line 437)
- `src/modals/menu/ViewMenuModal.vue:240` — `_.cloneDeep` only (used once, line 351)
- `src/modals/menuItem/MenuItemForm.vue:309` — `_.cloneDeep` only (used once, line 903)
- `src/dialogs/page/DeletePageContentDialog.vue:53` — `_.cloneDeep` only (used once, line 102)
- `src/modals/pageContents/PageContentForm.vue:209` — `_.cloneDeep` (line 447) and
  `_.upperFirst` (line 10)

`webpack.config.js` does not configure `lodash-webpack-plugin` or a
`babel-plugin-lodash` transform, and the app's `package.json` has no such
devDependency (confirmed: `grep -n lodash package.json` shows only the runtime
dep and its `@types/lodash` typings). With a plain `import _ from 'lodash'`,
webpack's tree-shaking cannot eliminate the ~70KB (minified) unused surface of
the library because the barrel re-export has side-effect-laden internals;
every one of these five chunks bundles the full library to reach two
single-purpose helper functions that Node/the browser both ship natively
equivalents for (`structuredClone`/JSON round-trip for a deep clone of plain
JSON-shaped form state; a one-line `s.charAt(0).toUpperCase() + s.slice(1)`
for `upperFirst`).

This is pure bundle bloat with no functional benefit: none of the five call
sites need lodash's edge-case handling (circular refs, Maps/Sets, functions)
— all five clone plain JSON-serializable modal/form state objects.

## What Changes

- Replace `import _ from 'lodash'` + `_.cloneDeep(...)` in the four
  single-use call sites (`ObjectModal.vue`, `ViewMenuModal.vue`,
  `MenuItemForm.vue`, `DeletePageContentDialog.vue`) with a local
  `structuredClone(...)` call (or, if a target browser/electron gap is a
  concern, a tiny local `deepClone()` helper using
  `JSON.parse(JSON.stringify(...))` — both are already zero-dependency and
  adequate for these plain-object payloads) — removing the lodash import
  entirely from those four files.
- In `PageContentForm.vue`, replace the barrel import with the two named
  cherry-pick imports lodash ships for exactly this purpose:
  `import cloneDeep from 'lodash/cloneDeep'` and
  `import upperFirst from 'lodash/upperFirst'` — each pulls in only that
  function's module, which webpack tree-shakes correctly (no plugin needed
  for path-specific imports).
- No behavior change; this is bundle-hygiene only. Not BREAKING.

## Non-goals

- Not touching any other app's lodash usage (fleet-wide policy, if wanted,
  is a cross-cutting concern for a company ADR — out of scope here).
- Not adding `lodash-webpack-plugin`/`babel-plugin-lodash` — the four
  single-use sites don't need lodash at all, and `PageContentForm.vue`'s
  cherry-pick imports solve the tree-shaking problem without a build-config
  change.
