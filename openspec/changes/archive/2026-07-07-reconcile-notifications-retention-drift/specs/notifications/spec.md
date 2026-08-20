## MODIFIED Requirements

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
