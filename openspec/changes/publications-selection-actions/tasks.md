# Tasks: publications-selection-actions

- [x] **T01**: `src/services/publicationActions.js`: `openSelectionDialog({ objects, props })` stages the rows and opens `props.dialog` or `props.modal`. Verify: `src/services/publicationActions.spec.js`.
- [x] **T02**: `CatalogPublicationsIndex.vue`: bind named bulk handlers to the selected rows and `props`; reload and clear the selection when the opened dialog closes; hide bulk actions when `showDeleteAction` is false. Verify: `CatalogPublicationsIndex.spec.js`.
- [x] **T03**: `src/modals/Modals.vue`: mount `MassLockObjects` (`massLockObjects`) and `MassUnlockObjects` (`massUnlockObjects`).
- [x] **T04**: `src/manifest.json`: Publish, Depublish, Lock, Unlock, Validate on `publicationPairConfig.bulkActions`. Verify: `npm run check:manifest`, `translateActionLabels.spec.js`.
- [x] **T05**: `src/services/translateActionLabels.js` translates `bulkActions[].label`.
- [ ] **T06**: nextcloud-vue draws a bulk action with `menu: true` under a More menu in the selection bar; once opencatalogi is on that release, set `menu: true` on Lock, Unlock, Validate, Merge and Migrate.
