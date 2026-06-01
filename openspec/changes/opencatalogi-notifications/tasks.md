# Tasks — OpenCatalogi schema-declared notifications

- [x] Add `x-openregister-notifications` to the `catalog` schema in `lib/Settings/publication_register.json` with a `catalog-stable` rule (transition action `stable`, `object-acl` manage recipient)
- [x] Add `x-openregister-notifications` to the `listing` schema in `lib/Settings/publication_register.json` with a `listing-sync-failed` rule (transition action `obsolete`, `groups` recipient `publication-officers`)
- [ ] Confirm the `publication-officers` group name matches the target deployment, or adjust the recipient group (deployment concern — group name is `publication-officers` as specified; operator must verify the group exists)
- [x] Do NOT add notifications to CMS-config schemas (`page`, `menu`, `theme`, `glossary`, `organization`) or to `publication` (no lifecycle/owner field)
- [x] Provide nl + en subjects on every rule (ADR-007 / ADR-025)
- [x] Validate that `lib/Settings/publication_register.json` is still well-formed JSON after the edits
- [ ] Confirm the catalogue-publish and listing-sync flows drive `status` through named OpenRegister transition actions (`stable`, `obsolete`) (runtime concern — lifecycle transitions are declared; verify publish/sync code calls OR transition API, not direct status writes)

## Acceptance criteria

- `lib/Settings/publication_register.json` parses as valid JSON.
- `catalog` declares a `catalog-stable` rule with a `transition` trigger and `object-acl` recipient.
- `listing` declares a `listing-sync-failed` rule with a `transition` trigger and `groups` recipient.
- No `x-openregister-notifications` key appears on `publication`, `page`, `menu`, `theme`, `glossary`, or `organization`.
- Every rule has both `nl` and `en` subject strings.
- No PHP, Vue, route, or migration files are changed.
