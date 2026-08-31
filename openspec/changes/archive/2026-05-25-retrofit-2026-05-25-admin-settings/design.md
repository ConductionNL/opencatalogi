# Design — Retrofit admin-settings (frontend)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

No code behavior changes. Documents already-shipped frontend behavior of the admin-settings
cluster and attaches `@spec` annotations.

## Code units → REQ map
| REQ | Code unit |
|---|---|
| SET-015 | src/views/settings/Settings.vue |
| SET-016 | src/settings.js |
| SET-017 | src/views/settings/UserSettings.vue |

## Notes
- The admin-settings backend is already specified (SET-001..014) and Bucket-1 annotated.
- `UserSettings.vue` is a placeholder dialog with no real preferences yet.
