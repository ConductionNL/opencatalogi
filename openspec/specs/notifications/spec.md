---
status: done
---

# notifications Specification

## Purpose
Schema-declared notifications for OpenCatalogi domain schemas. The `catalog`
and `listing` schemas in `lib/Settings/publication_register.json` carry the
`x-openregister-notifications` key, so the OpenRegister notification engine
dispatches `nc-notification` on a catalogue reaching `stable` and on a listing
sync failing — with no per-app notification code.

## Requirements
### Requirement: Catalogue publication notification

The OpenCatalogi `catalog` schema SHALL declare an
`x-openregister-notifications` rule that notifies the catalogue's
manage-ACL holders when it transitions to `stable`, with bilingual
(nl/en) subjects.

#### Scenario: Catalogue reaches stable

- **WHEN** a `catalog` object transitions through the `stable` action
- **THEN** the OpenRegister notification engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{title}}`

### Requirement: Listing sync-failure notification

The OpenCatalogi `listing` schema SHALL declare an
`x-openregister-notifications` rule that notifies the
`publication-officers` group when a federated listing sync fails
(transition to `obsolete`), with bilingual (nl/en) subjects.

#### Scenario: Listing sync fails

- **WHEN** a `listing` object transitions through the `obsolete` action
- **THEN** the engine dispatches an `nc-notification` to the `publication-officers` group with a nl/en subject referencing `{{title}}` and `{{statusMessage}}`

### Requirement: CMS-config and ownerless schemas are not notified

OpenCatalogi SHALL NOT declare `x-openregister-notifications` on the
CMS-config schemas (`page`, `menu`, `theme`, `glossary`, `organization`)
or on `publication` (which has no lifecycle or owner field).

#### Scenario: No notifications on config schemas

- **WHEN** the publication register JSON is inspected
- **THEN** no `x-openregister-notifications` key is present on `page`, `menu`, `theme`, `glossary`, `organization`, or `publication`

