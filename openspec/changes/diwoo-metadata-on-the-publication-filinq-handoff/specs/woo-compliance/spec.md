---
status: proposed
---

# DiWoo metadata on the publication: the filinq handoff

## ADDED Requirements

### Requirement: filinq writes the document type to its field (REQ-DWP-006)

The cross-app contract with filinq SHALL be: on handoff (filinq REQ-DDWPP-005), filinq writes the DiWoo documentsoort to the publication property `documentsoort`, as a resource URI from `GET /api/woo/documentsoorten`, or as that list's code or exact label, which OpenCatalogi normalises to the URI (REQ-DWP-002). filinq SHALL NOT write a document type into `summary`. OpenCatalogi SHALL refuse a value outside the list; filinq SHALL surface that refusal to the operator as REQ-DDWPP-005 already requires for an OpenRegister failure. When filinq is not installed nothing here changes. When OpenCatalogi is not installed, filinq's handoff stays disabled with its explanation (REQ-DDWPP-005).

#### Scenario: A filinq handoff lands in the field
<!-- @e2e exclude Cross-app save contract; proven on this side by DiwooCompletenessListenerTest::testTheFilinqHandoffPayloadIsAccepted, which saves the exact payload filinq's OpenCatalogiPublicationMap::toPublication() produces after its paired change. -->

- **GIVEN** filinq's handoff payload with `title`, `publicationDate` and `documentsoort` `besluit` and no `summary`
- **WHEN** it is saved as a publication
- **THEN** the publication's `documentsoort` holds the besluit URI and its `summary` is empty
