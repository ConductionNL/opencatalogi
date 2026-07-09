# Tasks: diwoo-tooi-vocabulary-binding

This change is `kind: mixed` (ADR-032): bundled value lists + an
`organization.tooiIdentifier` schema property (config) plus the sitemap-mapping
and validator code that consume them. Value-list/schema declarations land first.

- [x] Freeze the delta spec under
  `openspec/changes/diwoo-tooi-vocabulary-binding/specs/woo-compliance/spec.md`
  (ADDED WOO-TOOI-001…004); confirm `openspec validate diwoo-tooi-vocabulary-binding --strict` is green
  - Spec ref: specs/woo-compliance/spec.md (this change)
  - Acceptance: validator reports valid; existing WOO-001…010 untouched
- [x] Bundle three value lists (informatiecategorieën → TOOI URIs;
  organisatie-identificatoren; soortHandeling) in the OpenCatalogi register
  bundle as reference data, self-identifying as such
  - Spec ref: WOO-TOOI-004
  - Acceptance: the 17 categories, org-identifier lookup, and soortHandeling
    members are present and resolvable at render time
- [x] Add a `tooiIdentifier` property to the `organization` schema in
  `lib/Settings/publication_register.json`
  - Spec ref: WOO-TOOI-002
  - Acceptance: property present on the `organization` schema; optional; carries
    the TOOI organisatie URI
- [x] `SitemapService::mapDiwooDocument()`: resolve `informatiecategorie`,
  `publisher @resource`, and `soortHandeling` through the value lists; OMIT any
  axis that cannot resolve to an official URI (never emit a literal `@resource`)
  - Spec ref: WOO-TOOI-001, WOO-TOOI-002, WOO-TOOI-003
  - Acceptance: mapped values render official URIs; unresolved axes absent from
    the XML; default soortHandeling stays `ontvangst` as a value-list member
- [x] Add the "Validate DIWOO output" action: run the mapping in dry-run,
  collect per-document `{ documentLoc, axis, reason }` violations, surface in
  admin/publisher settings
  - Spec ref: WOO-TOOI-004
  - Acceptance: report lists documents with unresolved axes; sitemap still served
- [x] Newman: assert TOOI category/org/soortHandeling URIs on a fully-mapped
  seed publication and the omit-and-report behaviour on a seed publication whose
  organisation lacks a `tooiIdentifier`
  - Spec ref: WOO-TOOI-001…004
  - Acceptance: Newman collection green against a seeded WOO catalog
