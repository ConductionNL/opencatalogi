---
status: proposed
---

# Publication retention lifecycle

## MODIFIED Requirements

### Requirement: Scheduled publication (embargo) via future `publicatiedatum` (RET-001)
The system MUST support scheduling a publication by setting its own
`publicationDate` field (formerly `publicatiedatum`) to a future date-time while
its stored lifecycle state is `published` (REQ-PLC-001). Until that moment the
object MUST be invisible on every public surface: public publications API
(PUB-001/002), search, sitemaps (WOO-001), DCAT feed, and federation, because all
of them derive visibility from the one OR RBAC predicate
`{group:public, match:{status:"published", publicationDate:{$lte:$now}, depublicationDate:{$gte:$now}}}`.
A publication whose stored state is anything other than `published` MUST be
invisible on every public surface whatever its dates say. OpenCatalogi MUST NOT
implement a second embargo mechanism or visibility check (hydra ADR-022); the
lifecycle clause is part of the same predicate, not a second check. The
`unlisted` flag (REQ-PLC-006) is a listing filter, not a visibility check: an
unlisted record stays readable at its own link. The removed object-level
`@self.published` predicate is no longer available. From the scheduled moment
the object MUST be publicly visible without any further user action.

#### Scenario: Embargoed besluit invisible before its effective date
- GIVEN a publication in state `published` with `publicationDate` set to tomorrow 09:00
- WHEN the public publications API, search, and sitemap are requested today
- THEN the publication MUST NOT appear on any of them
- AND an anonymous direct fetch of the publication MUST return `404`

#### Scenario: Publication appears at the scheduled moment
- GIVEN the same publication
- WHEN the public surfaces are requested after tomorrow 09:00
- THEN the publication MUST appear on all of them with no manual action
- AND no OpenCatalogi cron or listener MUST be required to "flip" it (the
  predicate evaluation does)

#### Scenario: A draft with a past date stays private
<!-- @e2e exclude Read rule contract; proven by PublicationLifecycleReadRuleTest::testADraftWithAPastPublicationDateIsNotPublic. -->
- GIVEN a publication in state `draft` with `publicationDate` last week
- WHEN the public surfaces are requested
- THEN the publication MUST NOT appear on any of them
- AND an anonymous direct fetch MUST return `404`

#### Scenario: Public cache headers respect the embargo boundary
- GIVEN a cacheable public surface (sitemap, DCAT document) containing
  publications with a pending embargo in its scope
- WHEN cache validators (`Last-Modified`/`ETag`/max-age) are emitted
- THEN they MUST NOT cause a conditional `304`/cached response to mask the
  embargo moment by more than the surface's documented cache granularity

#### Scenario: Schedule set from the publish dialog
- GIVEN a user opens the publish confirmation dialog (PUB-018) on an `approved` publication
- WHEN they choose "Publish on" and pick a future date-time
- THEN the store action MUST run the `publish` transition with `publicationDate` set to that date-time
- AND the publication list MUST show a "Scheduled" status with the scheduled
  moment
