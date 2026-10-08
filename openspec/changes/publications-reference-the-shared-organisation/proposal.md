# Publications reference the shared organisation

## Summary

Publications reference OpenRegister's shared organisation instead of their own `organization` schema. Amended 2026-10-05: naming the organisational unit on a publication is what scopes its rights.

- Rows: 12.34 (amendment: REQ-SHO-102).
- Wave: 2.
- Depends on: `openregister/object-organisation-from-a-property` (https://github.com/ConductionNL/openregister/issues/4391), the `x-openregister-organisation: {fromProperty}` annotation (REQ-OOP-001) and its `occ openregister:organisation:reconcile` command.
- Decision: none of D1 to D13.
- Build rules: openspec/woo-build-rules.md

## Why

`organization` is a cross-app slug collision: opencatalogi and stackiq both
declare one, and a schema slug is global per organisation, so
`SchemaMapper::find()` returns whichever row it reaches first.

The reason both apps declared their own was structural, not careless.
`publication.organization` and `catalog.organization` are declared as
`{"format": "uuid", "$ref": "organization"}`, a `$ref` resolves against a
SCHEMA, and OpenRegister's Organisation was an ENTITY with no object
projection. There was nothing to point at.

openregister #3363 added that projection: `nc-organisation`, a read-only virtual
schema on the always-available `directory` register, following the `nc-user` and
`nc-group` pattern. This points the two properties at it.

## The frontend needs no change at all

`objectStore.getCollection('organization')` resolves a type through
`<type>_source`, `<type>_schema` and `<type>_register` and cares about nothing
else. So `organization` stays a first-class object type in the settings screen
and in the catalog picker, and only where those three keys POINT changes: from a
schema this app shipped, to OpenRegister's directory register.

That is the whole reason this change is small. Not one Vue file is touched.

## What changes

- `publication.organization` and `catalog.organization` now `$ref`
  `nc-organisation`.
- The `organization` schema is removed from the register descriptor AND from the
  `ooapi-catalog-publication` fragment, which shipped a second copy.
- `SettingsService` stops resolving `organization_*` from this app's own import
  result — it cannot, the schema is not ours any more — and resolves it from
  OpenRegister instead.

Both descriptor versions are bumped. The import is version-gated on them, and
changing one alone never applies.

## Soft failure, on purpose

OpenRegister may be absent, or an older version may not carry the projection.
Neither is a reason to fail an import, so the keys are simply left unset. That
surfaces as a picker with nothing to offer, rather than a broken install.

## Amendment 2026-10-05: Woo capability programme

Row 12.34, from `opencatalogi/_round1/compare/M1-rows.md`: "A publication names the organisational unit it was published for, and rights can be scoped to that unit". Ours (`baseline/openwoo.tsv`): partial, production. Evidence: "opencatalogi #publication.organization ($ref nc-organisation) names the organisation, which can be a unit because openregister lib/Db/Organisation.php carries a parent and children inherit access. Rights are scoped by OR multitenancy on the object's @self.organisation (MultiTenancyTrait), a separate field the publication's organization property does not set or follow, so naming the unit and scoping rights to it are two values nothing ties together". Re-checked on development at 35999c296: this change stands at 9 of 10 tasks plus the fleet run; nothing in it ties the two values.

What is added (REQ-SHO-102): the publication schema declares `x-openregister-organisation: {fromProperty: "organization"}`, the annotation `openregister/object-organisation-from-a-property` (OpenRegister, planned in this programme, wave 1) adds as REQ-OOP-001. OpenRegister then sets `@self.organisation` from `organization` on every save, refuses a contradicting `@self.organisation` (422) and a save by a non-member of the named unit (403), and fills an empty property. Existing publications are reconciled once with that change's `occ openregister:organisation:reconcile --schema publication`, dry run first. Fail closed: no fallback to the caller's active organisation when the named unit is not theirs, as REQ-OOP-001 states. Without the OpenRegister change merged, the annotation would be dropped on import as unknown (an unknown `x-openregister-*` key is dropped silently), so this amendment is built only after it, and a test asserts the annotation survives the import. Wave 2. No decision of D1 to D13 applies.

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 12.34 | A publication names the organisational unit it was published for, and rights can be scoped to that unit | partial | REQ-SHO-102, scenario "Naming the unit is what scopes the rights" |
