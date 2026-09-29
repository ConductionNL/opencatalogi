---
status: proposed
---

# Woo publication category

## ADDED Requirements

### Requirement: A publication stores the Woo information category it belongs to (REQ-WPC-001)

The publication schema SHALL carry an optional property `wooCategory` whose value is one of the codes `infocat001` to `infocat017`. A value outside that list SHALL be refused on save.

#### Scenario: An editor files a publication

- **GIVEN** an editor on a publication in a Woo-enabled catalogue
- **WHEN** the editor chooses "Jaarplannen en jaarverslagen" in the category select and saves
- **THEN** the stored publication has `wooCategory` equal to `infocat012`

#### Scenario: An unknown code is refused

- **GIVEN** a save request with `wooCategory` set to `infocat099`
- **WHEN** the request reaches OpenRegister
- **THEN** the save is refused with a validation error naming the property

### Requirement: Each category sitemap lists the publications filed under it (REQ-WPC-002)

`GET` on the sitemap of category `infocat012` for a Woo catalogue SHALL list the publications of that catalogue whose `wooCategory` is `infocat012` and no others. On an instance that still runs a register titled `woo`, the schema-title lookup SHALL contribute its rows as well. The DiWoo information category of a listed document SHALL come from `wooCategory` when it is set.

#### Scenario: The harvester reads one category

- **GIVEN** three publications, two with `infocat012` and one with `infocat004`
- **WHEN** the national Woo index harvester requests the sitemap of `infocat012`
- **THEN** the sitemap lists exactly the two publications filed under it

### Requirement: The editor is offered the 17 categories (REQ-WPC-003)

`GET /api/woo/categories` SHALL return the 17 codes with their Dutch and English names for an admin or an editor, and the publication form SHALL show them in a labelled select.

#### Scenario: The select lists all categories

- **GIVEN** an editor opening a new publication in a Woo-enabled catalogue
- **WHEN** the category select is opened
- **THEN** it lists 17 options, each named in the user's language

### Requirement: A batch publish from a Woo request files under the decision category (REQ-WPC-004)

Publishing a batch from a Woo request SHALL set `wooCategory` to `infocat014` on the publication it creates.

#### Scenario: A batch is published

- **GIVEN** an approved Woo request batch
- **WHEN** the batch is published
- **THEN** the created publication has `wooCategory` equal to `infocat014`
