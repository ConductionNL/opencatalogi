# Design — Retrofit publications (frontend)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

No code behavior changes. This change documents already-shipped frontend behavior of the
publications cluster and attaches `@spec` annotations to the implementing code units.

## Code units → REQ map
| REQ | Code unit |
|---|---|
| PUB-016 | `src/store/modules/object.js::publishObject` |
| PUB-017 | `src/store/modules/object.js::depublishObject` |
| PUB-018 | `src/dialogs/publication/PublishPublicationDialog.vue` |

## Notes
- The publications backend is already specified (PUB-001..015) and Bucket-1 annotated.
- The generic `object.js` store is shared plumbing; only its publication-specific
  `publishObject`/`depublishObject` methods are in scope here.
- `PublishPublicationDialog.vue` has a confirm-handler bug (copies a menu instead of
  publishing). The REQ specifies the intended behavior; the bug is flagged in the spec
  Notes and must be fixed by a separate code change.
