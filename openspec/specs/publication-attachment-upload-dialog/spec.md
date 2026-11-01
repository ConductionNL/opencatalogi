# publication-attachment-upload-dialog Specification

## Purpose
Editors add attachments from the publication page. The Attachments card opens the app's upload dialog for that publication, with labels, automatic publishing and a progress list per file, and lists the new files when the upload ends. Created by archiving change publication-attachment-upload-dialog (2026-10-30); holds PAU-001.

## Requirements

### Requirement: The attachments card opens the upload dialog (PAU-001)

The publication page's Attachments card SHALL offer "Add attachment". It MUST
open the app's upload dialog for that publication, with labels, automatic
publishing and a progress list per file, and the card MUST list the new files
once the dialog closes.

@e2e exclude The upload itself is UploadFiles.spec.js's; PublicationAttachmentsWidget.spec.js covers the opening and the refresh.

#### Scenario: Adding the legal advice to the Oostpolder decision

- **GIVEN** the page of "Besluit op het Woo-verzoek over windpark Oostpolder" with six attachments
- **WHEN** an editor chooses "Add attachment"
- **THEN** the upload dialog opens for that publication
- **AND** after uploading "Advies juridische afdeling.pdf" and closing the dialog the card lists seven attachments
