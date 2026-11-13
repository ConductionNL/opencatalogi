# woo-compliance

## ADDED Requirements

### Requirement: A publication rule names the information category its records are filed under (REQ-CVS-003)

A publication rule MUST name one Woo information category from the 17 the app
serves. A record published by the rule MUST carry that category as its
`wooCategory`, so it lands in that category's sitemap. The rule's preview MUST
show the category next to the records it would publish. A rule without a
category, or with a category the app does not serve, MUST NOT be saved.

#### Scenario: Council decisions are filed under their category
- **GIVEN** a rule on record type "raadsbesluit" with category infocat004 and the condition status is "Vastgesteld"
- **WHEN** a council decision with status "Vastgesteld" arrives
- **THEN** it is public with `wooCategory` infocat004
- **AND** it is listed in the catalogue's sitemap for infocat004

#### Scenario: The preview shows the category
- **GIVEN** the same rule as a draft
- **WHEN** an administrator previews it
- **THEN** each record it would publish is shown with "Besluiten van de raad (infocat004)"

#### Scenario: A rule without a category
- **WHEN** an administrator saves a rule with no category
- **THEN** the save is refused and names the category field
