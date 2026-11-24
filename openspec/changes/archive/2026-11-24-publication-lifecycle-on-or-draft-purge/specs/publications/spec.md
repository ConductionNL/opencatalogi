---
status: proposed
---

# Publications: permanent delete of a draft

## ADDED Requirements

### Requirement: A draft that was never released is deleted permanently with everything on it (REQ-PLC-004)

`DELETE /api/publications/{id}/draft` SHALL delete permanently a publication whose stored state is `draft` or `in_review` and that was never released, together with its attached files, its document references, its `depublication`, `publicationProcess` and `wooAssessment` records that point at it, and any suggestion records that point at it. "Never released" SHALL mean: `firstReleasedAt` empty, no `depublication` record names it, and the audit trail of the object holds no entry in which `status` was `published`. Any one of those that holds, or an audit trail that cannot be read, SHALL refuse the delete with 409 and the reason. The caller SHALL need the update right on the publication. Before removing anything the service SHALL list every object and file it will remove. It SHALL then remove them, with OpenRegister's `ObjectService::deleteObject(..., permanent: true)` for objects, and write one audit entry through `AuditTrailMapper::createAuditTrail()` with action `publication.draft.purged`, the publication's title and id, and the list. When a removal fails part way it SHALL stop, keep the publication, and answer 500 naming what was already removed.

#### Scenario: A draft and everything on it goes

- **GIVEN** a draft publication that was never released, with two files and one document reference
- **WHEN** an editor chooses Delete permanently on its page and confirms
- **THEN** the publication, both files and the reference are gone and cannot be restored
- **AND** one audit entry `publication.draft.purged` lists the publication and the three removed items

#### Scenario: Anything ever released is refused
<!-- @e2e exclude Fail-closed refusal; proven by DraftPurgeServiceTest::testAPublicationThatWasEverPublishedIsRefused, DraftPurgeServiceTest::testAnUnreadableAuditTrailRefuses. -->

- **GIVEN** a publication in state `draft` that was published last month and retracted
- **WHEN** `DELETE /api/publications/{id}/draft` is called
- **THEN** the answer is 409 naming the earlier release
- **AND** nothing is removed

#### Scenario: A failure part way keeps the publication
<!-- @e2e exclude Failure path; proven by DraftPurgeServiceTest::testAFailurePartWayKeepsThePublicationAndNamesWhatWent. -->

- **GIVEN** a draft with two files, where deleting the second file fails
- **WHEN** the delete runs
- **THEN** the publication still exists and the answer names the first file as removed
