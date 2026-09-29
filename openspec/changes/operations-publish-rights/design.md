# Design: operations-publish-rights

## Mechanism

OpenRegister's `PropertyRbacHandler` (`openregister lib/Service/PropertyRbacHandler.php`, `canUpdateProperty()`) enforces an `authorization.update` list on a single property. The publication schema in `lib/Settings/publication_register.json` (`publication`, its `properties.publicationDate` and `properties.depublicationDate`) gets

`"authorization": {"update": [{"group": "opencatalogi-publishers"}]}`

and the `admin` group is always allowed by OpenRegister. An editor who saves a publication with a changed `publicationDate` is refused with OpenRegister's property error; an unchanged value passes.

## Screens

The Actions menu of `PublicationDetail` (from `publications-publish-and-withdraw-action`) already gates each action with `visibleWhen`. `GET /api/publications/{id}/visibility` gains a `canPublish` boolean computed from the caller's group membership, and the publish and withdraw actions add `canPublish` to their conditions. The two endpoints `POST /api/publications/{id}/publish` and `POST /api/publications/{id}/withdraw` check the same group and answer 403 with a message that names the group.

## Group

The group `opencatalogi-publishers` is created on install and on repair if absent (`lib/Migration`, in the style of the existing repair steps). An existing instance keeps working: until the group has a member, only administrators can publish, so the repair step adds nothing implicitly. This is a change of behaviour for editors and the release note says so.

## Risks

An existing editor loses the ability to publish on upgrade. The settings line and the release note are the mitigation; no data changes.
