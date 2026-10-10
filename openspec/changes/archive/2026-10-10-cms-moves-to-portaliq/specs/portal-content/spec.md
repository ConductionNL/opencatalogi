# Portal content

## ADDED Requirements

### Requirement: A page becomes a portal page (REQ-CMS-101)

An OpenCatalogi page MUST be movable to a Portaliq portal, preserving its title,
its path and its content.

The target portal MUST be named by the operator. A Portaliq page requires one,
and nothing in the source data identifies it, so the command MUST refuse rather
than choose.

A page's `slug` becomes an in-portal `route` with a leading slash. A page with no
slug becomes the portal root.

A migrated page MUST be published. Every source page was live, and importing
them as drafts would take a working site offline.

#### Scenario: A slugless page becomes the root

- **GIVEN** a page with no slug
- **WHEN** it is migrated
- **THEN** its route is `/`.

#### Scenario: The command refuses without a portal

- **WHEN** the migration runs with no portal named
- **THEN** it refuses and explains that the source data cannot supply one.

### Requirement: Content blocks become widgets (REQ-CMS-102)

A page's content blocks MUST become widgets on a 12-column grid, stacked in the
order they were authored.

The block-to-widget mapping MUST be declared. A block type with no mapping MUST
be refused: a guessed widget key produces a page that saves, renders nothing,
and reports no error.

A block's data MUST be rewritten to the keys the target widget reads. A `text`
block's `content` becomes a `markdown` widget's `markdown`.

A property the target widget does not declare MUST be reported.

#### Scenario: A text block renders after migration

- **GIVEN** a `text` block carrying `content`
- **WHEN** it is migrated
- **THEN** the resulting widget carries that text under `markdown`.

#### Scenario: An unknown block type is refused

- **GIVEN** a block whose type has no declared widget
- **WHEN** the migration runs
- **THEN** that page is refused and the type is named.

#### Scenario: Stacked blocks do not overlap

- **GIVEN** three blocks
- **WHEN** they are migrated
- **THEN** each widget's `gridY` clears the one above it.

### Requirement: A menu becomes a portal menu (REQ-CMS-103)

A menu MUST keep its title, position and items.

`groups`, `hideBeforeLogin` and `icon` have no Portaliq counterpart. They MUST be
reported when present, so their loss is visible before it is discovered in a
rendered page.

#### Scenario: A menu with no unmappable fields reports nothing

- **GIVEN** a menu using only title, position and items
- **WHEN** it is migrated
- **THEN** nothing is reported as uncarried.
