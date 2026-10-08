# Tasks: publication-delete-says-what-it-removes

- [ ] **T01** (nextcloud-vue, cross-repo): add an optional `description` prop to `CnDeleteDialog` (muted paragraph under the message, omitted when empty) and a `deleteDialog` prop on `CnIndexPage` (`title`, `message`, `description`) forwarded to the single-delete `CnDeleteDialog` (design D1). Vitest for forwarded and default texts. Release within the current major.
- [ ] **T02**: In `src/manifest.json`, Publications page `publicationPairConfig`, add `deleteDialog` with the values of design D2 (REQ-PDEL-001, REQ-PDEL-002). Raise `@conduction/nextcloud-vue` to the release of T01. Verify: manifest validator passes; Vitest on `CatalogPublicationsIndex.spec.js` that the publication pair gets `deleteDialog` and another pair does not.
- [ ] **T03**: Strings in `l10n/en.json` and `l10n/nl.json`: "Delete publication" / "Publicatie verwijderen", the question / "Wilt u "{name}" verwijderen? Dit kan niet ongedaan worden gemaakt.", the consequence / "De publicatie en haar bijlagen verdwijnen van de publieke pagina en uit de Woo-sitemap. Archiveren bewaart ze juist voor de bewaartermijn." Run `npm run test:l10n`.
- [ ] **T04**: Write `tests/e2e/publication-delete.spec.ts` for REQ-PDEL-001 and REQ-PDEL-002.
- [ ] **T05**: Set row `pub-delete` to `building` when T02 is merged and to `built` with `opencatalogi: yes` when T04 is green.
