---
status: done
---

# notifications Specification

## Purpose
Schema-declared notifications for OpenCatalogi domain schemas. The `catalog`,
`listing`, and `publication` schemas in `lib/Settings/publication_register.json`
carry the `x-openregister-notifications` key, so the OpenRegister notification
engine dispatches `nc-notification` on a catalogue reaching `stable`, on a
listing sync failing, and on the `publication` retention rules
(expiring-soon / review-required, owned by `publication-retention-lifecycle`,
RET-008) — all with no per-app notification code.
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

OpenCatalogi SHALL NOT declare `x-openregister-notifications` on the CMS-config
schemas (`page`, `menu`, `theme`, `glossary`, `organization`), which have no
lifecycle or owner field to notify against.

The `publication` schema is **excluded from this prohibition**: it carries an
`x-openregister-lifecycle` and an `x-openregister-notifications` block (the
retention-expiring-soon / retention-review-required rules). Those notification
rules are owned and specified by `publication-retention-lifecycle` (RET-008,
"Retention notifications are schema-declared"); they MUST remain the canonical
ADR-031 declarative dialect with no imperative per-app dispatch. This requirement
therefore governs only the ownerless CMS-config schemas.

#### Scenario: No notifications on ownerless config schemas

- **WHEN** the publication register JSON is inspected
- **THEN** no `x-openregister-notifications` key is present on `page`, `menu`,
  `theme`, `glossary`, or `organization`

#### Scenario: Publication carries its retention notifications

- **WHEN** the `publication` schema in the register JSON is inspected
- **THEN** it MAY carry an `x-openregister-notifications` block for the retention
  rules governed by `publication-retention-lifecycle` (RET-008)
- **AND** that block MUST be the declarative ADR-031 dialect (no imperative
  notification code in `lib/`)

