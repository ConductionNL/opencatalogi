---
kind: code
depends_on: []
---

# Proposal: catalogue-staff-access

## Summary

An administrator names, per catalogue, the groups whose staff may see its unpublished publications and the groups who may edit them. OpenCatalogi writes that as OpenRegister authorization rules on the schemas the catalogue covers, narrowed to the catalogue's own filters, so OpenRegister enforces it on every read and write. The setup wizard's "private, granted groups only" choice then does what it says.

## Why

Row `cat-access`, "Limit which staff can see or edit the publications of a catalogue." State `specified`, ours `no`, owner openregister. Evidence: "publication_register.json:291 authorization is schema-wide (public on publicationDate, else 'authenticated'), with no create/update rules; SetupController.php:460-469 the 'private (granted groups only)' scope choice only sets listed=false; no catalog-level group field in the catalog schema."

The linked change is OpenRegister's `rbac-inherits-to-children`, all 8 tasks ticked: a grant on a parent object reaches its descendants over a declared parent property. It does not reach this row. Its declaration requires the parent property to be "a declared reference to the same schema", and a publication is not a child object of its catalogue: a catalogue is a selection of registers, schemas and equality filters (`catalog.registers`, `catalog.schemas`, `catalog.filters`), and a publication belongs to it by matching them. So the delivered change is the wrong tool, and nothing on the OpenCatalogi side declares per-catalogue access.

What does fit is OpenRegister's conditional rules (`rbac-scopes`, "Conditional Scopes with Dynamic Variables" and "Nextcloud Group Mapping"): a rule `{group, match}` per action, which the publication schema already uses for its public read. A catalogue's membership is its schemas plus its filters, which is exactly a `match`.

## What changes

- The catalogue gets `staffAccess`: `{restricted: bool, readGroups: [group ids], editGroups: [group ids]}`. Off by default, so nothing changes for existing catalogues.
- `CatalogAccessService` recomputes the `authorization` of every schema a catalogue covers whenever a catalogue's access, schemas or filters change. For a schema it collects every catalogue that covers it. A restricted catalogue contributes `{group: <g>, match: <its filters>}` to `read` (read and edit groups) and to `create`, `update`, `delete` (edit groups). An unrestricted catalogue contributes the plain `authenticated` rule it has today, narrowed to its filters when it has filters. The public read rules stay as they are.
- The catalogue edit page gets a "Staff access" section: Restricted on or off, Who can see, Who can edit.
- The setup wizard's private choice sets `staffAccess.restricted` with the groups it asks for, besides `listed: false`.
- Administrators keep full access; OpenRegister's admin bypass is unchanged.

## Rows

| row | name | ours | what this closes |
|---|---|---|---|
| `cat-access` | Limit which staff can see or edit the publications of a catalogue. | no | per-catalogue staff groups, enforced by OpenRegister |

## Limits, stated

- Two catalogues over the same schema and the same filters hold the same publications, so they share their access: the union of their groups. The section says so when it happens.
- A publication matched by an unrestricted catalogue as well as a restricted one stays visible to all staff. The section lists overlapping catalogues so the administrator can narrow one.

## Out of scope

- Per-publication sharing. OpenRegister's object-level sharing does that already.
- Changing `rbac-inherits-to-children`. It stays right for same-schema trees.
