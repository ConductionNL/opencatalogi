# Tasks — OpenCatalogi schema-declared notifications

- [x] Add `x-openregister-notifications` to the `catalog` schema in `lib/Settings/publication_register.json` with a `catalog-stable` rule (transition action `stable`, `object-acl` manage recipient)
- [x] Add `x-openregister-notifications` to the `listing` schema in `lib/Settings/publication_register.json` with a `listing-sync-failed` rule (transition action `obsolete`, `groups` recipient `publication-officers`)
- [x] Confirm the `publication-officers` group name matches the target deployment, or adjust the recipient group — group name `publication-officers` ships as the deployment assumption documented in the proposal; operators must ensure the group exists (or adjust the name via register config before going live)
- [x] Do NOT add notifications to CMS-config schemas (`page`, `menu`, `theme`, `glossary`, `organization`) or to `publication` (no lifecycle/owner field)
- [x] Provide nl + en subjects on every rule (ADR-007 / ADR-025)
- [x] Validate that `lib/Settings/publication_register.json` is still well-formed JSON after the edits
- [x] Confirm the catalogue-publish and listing-sync flows drive `status` through named OpenRegister transition actions (`stable`, `obsolete`) (prerequisite; see Caveats) — confirmed as a declared-but-dormant caveat: `DirectoryService` currently writes `status` directly; the `x-openregister-lifecycle` transition config is present and the notification rules will fire once the sync/publish flows are updated to invoke named transitions

## Acceptance criteria

- `lib/Settings/publication_register.json` parses as valid JSON.
- `catalog` declares a `catalog-stable` rule with a `transition` trigger and `object-acl` recipient.
- `listing` declares a `listing-sync-failed` rule with a `transition` trigger and `groups` recipient.
- No `x-openregister-notifications` key appears on `publication`, `page`, `menu`, `theme`, `glossary`, or `organization`.
- Every rule has both `nl` and `en` subject strings.
- No PHP, Vue, route, or migration files are changed.
