# Design — Retrofit file-management (frontend)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

No code behavior changes. Documents already-shipped frontend behavior of the
file-management cluster and attaches `@spec` annotations.

## Code units → REQ map
| REQ | Code units |
|---|---|
| FIL-016 | src/modals/generic/UploadFiles.vue |
| FIL-017 | src/dialogs/attachment/DeleteAttachmentDialog.vue |
| FIL-018 | src/modals/attachment/EditAttachmentModal.vue |
| FIL-019 | src/composables/UseFileSelection.js, src/dialogs/attachment/MassAttachmentModal.vue |

## Notes
- The file-management backend is already specified (FIL-001..015) and Bucket-1 annotated.
- Frontend attachment ops call the OpenRegister files endpoints directly.
