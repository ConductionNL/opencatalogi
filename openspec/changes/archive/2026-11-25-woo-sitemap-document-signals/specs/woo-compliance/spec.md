---
status: proposed
---

# Woo sitemap document signals

## ADDED Requirements

### Requirement: A document's lastmod follows its metadata as well as its file (REQ-WSD-001)

The `lastmod` of each DiWoo document in a category sitemap page SHALL be the latest of the file's modified time, the file's published time and the owning publication's last update time. A change to the publication's metadata alone SHALL move the `lastmod` of every document of that publication.

#### Scenario: An editor corrects the title of a published decision

- **GIVEN** a publication whose file was published on 1 March and whose title an editor corrects on 20 March
- **WHEN** the Woo-index harvester reads the category sitemap page that lists the file
- **THEN** that document's `lastmod` is 20 March

#### Scenario: A new file version is uploaded

- **GIVEN** a publication last edited on 1 March
- **WHEN** an editor replaces its file on 5 March and the harvester reads the sitemap page
- **THEN** that document's `lastmod` is 5 March

### Requirement: Documents of one publication say they belong together (REQ-WSD-002)

When a publication has two or more files in a category sitemap, every one of those documents SHALL carry an `isPartOf` relation. Without a marked main document, the relation SHALL point at the publication's public API URL. A publication with one file SHALL carry no relation.

#### Scenario: A decision with two annexes

- **GIVEN** a publication with three files and no main document marked
- **WHEN** the harvester reads the sitemap page
- **THEN** each of the three documents carries `isPartOf` with the publication's public API URL as its resource

#### Scenario: A single document

- **GIVEN** a publication with one file
- **WHEN** the harvester reads the sitemap page
- **THEN** that document carries no `isPartOf` and no `hasParts`

### Requirement: A main document lists its parts (REQ-WSD-003)

When exactly one file of a publication carries the label `main-document`, that document SHALL list every other file of the publication as a part, and every other document's `isPartOf` SHALL point at the main document. When more than one file carries the label, the sitemap SHALL fall back to REQ-WSD-002 and the DiWoo validator SHALL report the publication.

#### Scenario: An editor marks the decision as the main document

- **GIVEN** a publication with a decision and two annexes, and the decision's file labelled `main-document`
- **WHEN** the harvester reads the sitemap page
- **THEN** the decision's document lists both annexes as parts
- **AND** each annex's `isPartOf` points at the decision's document

#### Scenario: Two files marked by mistake

- **GIVEN** a publication where two files carry `main-document`
- **WHEN** an administrator runs Validate DIWOO output for the catalogue
- **THEN** the report names the publication and says two files are marked as the main document
