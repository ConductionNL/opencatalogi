# Design: catalogue-staff-access

## D1. Why rules on the schema, not a new OpenRegister feature

| Option | Verdict |
|---|---|
| `x-openregister-hierarchy` from `rbac-inherits-to-children` | Refused by OpenRegister: the parent property must reference the same schema. A publication has no catalogue reference either. |
| A `catalog` reference on every publication plus a cross-schema hierarchy in OpenRegister | Needs an OpenRegister change and a backfill, and breaks the many-catalogue membership a catalogue's filters allow. |
| Conditional rules `{group, match}` per action on the covered schemas | Exists on openregister development, already used by the publication schema's public read. Chosen. |

OpenCatalogi writes the rules on the schema entity through OpenRegister's SchemaMapper (its schema service has no general update; pipelinq, procest and planninq write schema rules the same way), and never evaluates access itself (ADR-022).

## D2. The rule set per schema

Input: every catalogue whose `schemas` contains the schema. For each:

- unrestricted, no filters: `"authenticated"` in `read`, `create`, `update`, `delete` (today's effective behaviour for staff)
- unrestricted, with filters: `{group: "authenticated", match: <filters>}`
- restricted: for each read group `{group: g, match: <filters>}` in `read`; for each edit group the same in `read`, `create`, `update`, `delete`

The public read rules of `publication_register.json` are kept as the first entries of `read`. The result is written only when it differs from what is stored. The previous rule set is kept in IAppConfig key `catalog_access_previous_<schemaId>` so a mistake can be reverted from the settings page in one click.

A `create` rule with a `match` is evaluated by OpenRegister on the object being created, so an editor of catalogue A cannot create a publication that falls outside A's filters.

## D3. When it runs

`CatalogAccessService::recompute(int $schemaId)` is called from `CatalogAccessListener` on catalogue save (`CatalogSchemaEventListener` normalises pre-save; the new listener recomputes post-save; no re-save of the catalogue itself, CAT-012) and on catalogue delete, for the union of old and new schemas. A repair step runs it once for every schema on upgrade.

## D3a. Who may change it

The catalog schema has no update rule, so any signed-in user can edit a catalogue. `CatalogAccessListener` therefore refuses a write that changes `staffAccess` unless the user is an administrator, on `ObjectCreatingEvent` / `ObjectUpdatingEvent`, before OpenRegister stores it (`stopPropagation` with the reason). A write without a session (setup, import, a background job) is the system's own and passes.

While no covering catalogue is restricted the schema keeps the rules it has; the authorization from before the first restriction is kept in IAppConfig and written back when the last restriction is lifted.

## D4. Screen

No board draws staff access. Board `OcCatalogusBewerken` is the edit page; the section goes after its "Configuration" block with: a switch "Only these groups can see and edit this catalogue's publications", two `NcSelect` group pickers with `inputLabel` "Who can see unpublished publications" and "Who can edit", and a note listing other catalogues over the same schemas ("Also covers these publications: Woo requests (no restriction)"). The catalogue page `OcCatalogus` shows a line "Staff access: restricted to Griffie, Woo-team" in its metadata.

## D5. Tests

`CatalogAccessServiceTest`: unrestricted stays as today; restricted writes group rules with the filters; two catalogues on one schema union; public rules kept first; no write when unchanged. An OpenRegister-backed test (skipped with a named reason without OpenRegister) reads a draft publication as a user outside the read groups (refused) and inside (allowed). e2e on the edit page.
