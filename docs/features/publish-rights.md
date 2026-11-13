# Publish rights: editors and publishers

Editing a publication and making it public are separate rights. Anyone who may edit a
publication can still change its title, summary, themes and attachments. Only members of
the Nextcloud group `opencatalogi-publishers` and administrators may publish it, withdraw
it or publish it again.

## How it is enforced

Two properties decide whether a publication is public: `publicationDate` and
`depublicationDate`. In the publication schema both carry a property-level update rule
for the group `opencatalogi-publishers`. OpenRegister enforces that rule on every save,
whichever screen or API the save comes from:

- An editor outside the group who saves a publication with a new `publicationDate` or
  `depublicationDate` is refused, and the error names the property.
- An editor who saves a changed title with the dates unchanged is not refused.
- Reading the dates is not affected.

The publication page asks `GET /api/publications/{id}/visibility`, which now also answers
`canPublish`. **Publish now**, **Withdraw** and **Publish again** only show when it is
true. `POST /api/publications/{id}/publish` and `POST /api/publications/{id}/withdraw`
answer 403 for a caller outside the group, with the error `not-a-publisher` and a
message that names the group.

## Setting it up

The app creates the group `opencatalogi-publishers` on install and on upgrade if it is
missing. It never adds anyone to it. Add publishers in Nextcloud's user management.

The Woo section of the OpenCatalogi admin settings shows the group, whether it exists and
how many members it has (`GET /api/settings/publish-rights`, administrators only).

## Upgrading

This changes what editors can do. After the upgrade an editor who is not in the group can
no longer publish or withdraw, and until the group has a member only administrators can.
No data changes.
