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

## ADDED Requirements

Amendment 2026-10-05, Woo capability programme, row 4.16.

### Requirement: A withdrawn attachment carries its withdrawal on the file (REQ-ATT-103)

`PublicationStateController::withdrawFile()` SHALL, besides removing the public share and storing the depublication (REQ-PPW-004), set the file's depublication moment in its OpenRegister publication window (REQ-FPW-101) to now, and store the reason and the editor in the file's metadata through OpenRegister's file service. The attachments list of the publication page SHALL show a withdrawn file as withdrawn, with the date and the reason. `DcatMappingService` SHALL leave a file whose window has ended out of the dataset's distributions.

#### Scenario: The withdrawal is on the file
<!-- @e2e exclude File metadata contract; proven by PublicationStateControllerTest::testWithdrawFileSetsTheFilesDepublicationAndReason, which fails on today's code because only the share is removed. -->

- **GIVEN** a public publication with a decision and an annex
- **WHEN** an editor withdraws the annex with the reason "Bevat een woonadres"
- **THEN** the annex's publication window ends now and its metadata holds the reason and the editor
- **AND** the DCAT feed lists the decision and not the annex

#### Scenario: The editor sees it

- **GIVEN** a withdrawn annex
- **WHEN** an editor opens the publication
- **THEN** the annex is marked withdrawn with the date and the reason

### Requirement: A withdrawn attachment stays withdrawn (REQ-ATT-104)

`EventService::publishObjectAttachments()` and every other OpenCatalogi path that shares a publication's files SHALL skip a file whose publication window has ended, or that a stored depublication names. Only `POST /api/publications/{id}/files/{fileId}/republish` with a reason, by a user who may update the publication, SHALL reopen such a file; it SHALL clear the window's end, share the file again and record the reason.

#### Scenario: A withdrawn annex stays withdrawn when the publication is saved
<!-- @e2e exclude Server-side auto-publish path; proven by EventServiceTest::testAutoPublishSkipsAWithdrawnFile, which fails on today's code because publishObjectAttachments shares every file without a share token. -->

- **GIVEN** a public publication with `auto_publish_attachments` on and a withdrawn annex
- **WHEN** an editor saves a new summary on the publication
- **THEN** the annex has no public share and is not in the category sitemap

#### Scenario: An editor puts an annex back on purpose
<!-- @e2e exclude API contract; proven by PublicationStateControllerTest::testRepublishFileNeedsAReasonAndReopensTheWindow. -->

- **GIVEN** a withdrawn annex
- **WHEN** an editor republishes it with the reason "Woonadres is weggelakt"
- **THEN** it is public again and the reason is recorded
