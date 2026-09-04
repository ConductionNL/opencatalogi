# Publications reference the shared organisation

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
