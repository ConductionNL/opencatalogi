# Publication attachments

## ADDED Requirements

### Requirement: An attachment is a file on the publication (REQ-ATT-101)

A publication's attachment MUST be a file attached to the publication, not a
separate object linking to it. OpenRegister resolves a file chunk to its owning
object, so a body-text match in an attachment then surfaces the publication
directly, with no schema widening.

The migration MUST resolve the `publication` link in every shape live data
holds: a bare uuid string, an object carrying `id`, and an object carrying only
`slug` and `title`. A slug MUST be resolved as a slug: treating one as a uuid
finds nothing and reports the publication as missing.

#### Scenario: A document links its publication by uuid

- **GIVEN** a document whose `publication` is a uuid string
- **WHEN** the link is read
- **THEN** it identifies that publication by id.

#### Scenario: A document links its publication by slug only

- **GIVEN** a document whose `publication` is `{"slug": "x", "title": "X"}`
- **WHEN** the link is read
- **THEN** it identifies that publication by slug, not by id.

#### Scenario: A document with no files is left in place

- **GIVEN** a document carrying metadata but no files
- **WHEN** the migration runs
- **THEN** it is skipped and reported, because migrating it would delete its
  metadata rather than move it.

#### Scenario: A document whose publication is missing is left in place

- **GIVEN** a document naming a publication that cannot be found
- **WHEN** the migration runs
- **THEN** it is skipped and reported.

### Requirement: The document's metadata lands on the file (REQ-ATT-102)

The migration MUST carry the document's description onto the file, its title as
a label, and its publication window onto the file's window.

`summary` and `description` are two pieces of prose about the same attachment,
so both MUST be carried, joined rather than one overwriting the other.

An unparseable date MUST become null, never the migration's own runtime. A
window starting when the command ran would publish every attachment at that
moment, which is the opposite of preserving what was set.

#### Scenario: Summary and description are both kept

- **GIVEN** a document carrying both a summary and a description
- **WHEN** its file metadata is derived
- **THEN** the file's description contains both.

#### Scenario: An identical summary and description are not duplicated

- **GIVEN** a document whose summary and description are the same text
- **WHEN** its file metadata is derived
- **THEN** that text appears once.

#### Scenario: An unparseable date is dropped

- **GIVEN** a document whose `publicationDate` cannot be parsed
- **WHEN** its file metadata is derived
- **THEN** the window's start is null, not the current time.
