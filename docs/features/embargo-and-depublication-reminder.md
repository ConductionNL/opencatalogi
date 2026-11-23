# Embargo, lifting it, and the depublication reminder

A publication with a publication date in the future is under embargo: it becomes public on
that date and not before. This page explains what holds an embargo, who may lift it, and the
reminder editors get a week before a publication is taken down automatically.

## The embargo

A publication is under embargo while its stored `publicationDate` lies after now. Every write
that would release it early is refused, whatever screen or API it comes from:

- Moving `publicationDate` to an earlier moment is refused for a caller who may not lift an
  embargo. The refusal names the property and `embargoUntil`, the stored date.
- Moving the date later, or clearing it, is allowed.
- A write that leaves the date as it is, such as a changed title, is not affected.
- A write that would release it early without a signed-in user behind it is refused rather
  than let through.

The check runs on OpenRegister's update event for the publication schema, so a save through
the OpenRegister object API is held to the same rule as the OpenCatalogi screens.

**Publish now** on a publication under embargo answers `423` with the error `embargo-holds`,
the property `publicationDate` and `embargoUntil`. Nothing is saved.

## Lifting an embargo

Members of the lift groups may publish a publication before its date. They also need the
right to edit that publication.

`POST /api/publications/{id}/lift-embargo` takes:

| Field | Meaning |
| --- | --- |
| `reason` | Why the embargo is lifted. Required. |
| `publicationDate` | The new publication date, usually now. Required. |

A scheduled publication is published at the new date through the publish transition. One
that is already published keeps its state and gets the new date. Every lift writes one audit
entry, `publication.embargo.lifted`, with the user, the reason, the previous date and the new
date. The answer carries `publicationDate` and `previousPublicationDate`.

| Answer | When |
| --- | --- |
| `401 not-logged-in` | Nobody is signed in. |
| `403 not-allowed-to-lift` | The caller is in no lift group, or may not edit the publication. |
| `404 not-found` | The publication does not exist. |
| `422 reason-missing` | No reason was given. |
| `422 invalid-date` | The new date cannot be read. |
| `422 transition-not-allowed` | The lifecycle does not allow publishing from the stored state. |
| `500 lift-not-logged` | The publication was published, but the audit entry could not be written. |

### Who may lift

Administrators set the lift groups under **Publishing Options**, in the field **Groups that
may lift an embargo**: Nextcloud group ids, separated by commas. The setting is stored as
`publication_embargo_lift_groups`. Its default is `admin`, so until it is set only
administrators may lift an embargo. When the stored value cannot be read, the default
applies.

## The depublication reminder

A publication with a `depublicationDate` is taken off the public side automatically on that
date. A week before, the editors who may manage it get one Nextcloud notification:

> Publication *title* is depublished automatically on *date*

The rule is declared on the publication schema (`x-openregister-notifications`,
`depublication-due-soon`) and OpenRegister checks it once a day. Each date reminds once: the
reminder comes again only when the depublication date changes. Nothing has to be set up; an
instance without OpenRegister's scheduled notifications sends no reminder.

## Related

- [Publish rights](./publish-rights.md): who may publish, withdraw and publish again.
