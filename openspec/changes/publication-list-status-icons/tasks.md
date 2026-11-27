# Tasks: publication-list-status-icons

- [x] **T01**: In `CatalogPublicationsIndex.vue`, take `cardAccent` from the row's server `state` instead of its dates, pass `rowIcon` and `rowClass` to `CnIndexPage` from `cardAccent`, and color the icon per status class (REQ-PSI-001). Verify: Jest in `CatalogPublicationsIndex.spec.js` for the icon and class of every state, of a draft with past dates, and of another schema's row.
- [x] **T02**: Write `tests/e2e/publication-list-status-icons.spec.ts` for the three scenarios of REQ-PSI-001, with the public and withdrawn fixtures walked to `published` through the lifecycle.
