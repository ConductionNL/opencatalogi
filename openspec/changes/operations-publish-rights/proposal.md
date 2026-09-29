---
kind: code
depends_on: [publications-publish-and-withdraw-action]
---

# Proposal: operations-publish-rights

## Why

Editors and publishers cannot be given different rights. Publishing is a write of the `publicationDate` field (`lib/Service/EventService.php`, `publishObject()`, and the public read rule on `publicationDate` in `lib/Settings/publication_register.json`), and the schema's `authorization` block has read rules only. Anyone who may edit a publication may therefore also publish it, and an administrator cannot separate the two in OpenRegister's schema editor.

Row, opencatalogi matrix: `ops-roles`, "Give editors and publishers different rights on publication actions." Own rating no, state none.

Demand, quoted from the matrix cells for this row: DKAN and xxllnc Publiceren are rated yes (CKAN and Decos rated partial). Two competitors rated yes, so the decision is build.

## What changes

- The properties that make a publication public or take it down, `publicationDate` and `depublicationDate`, get property-level update authorization in the publication schema: only members of the group `opencatalogi-publishers` and administrators may change them.
- The publish, withdraw and publish again actions from `publications-publish-and-withdraw-action` are hidden from a user who lacks the right, and refused with a plain message if called anyway.
- Editing every other property stays as it is today.
- The Woo settings section gets a line that names the group and says whether it exists and how many members it has, so an administrator can see the right is set up.

## Rows this closes

| matrix | row id | what is missing |
|---|---|---|
| opencatalogi | `ops-roles` | a separate publish right |

## Out of scope

A role editor inside OpenCatalogi. Groups are Nextcloud groups. Approval before publishing (a second person) is not part of this change.
