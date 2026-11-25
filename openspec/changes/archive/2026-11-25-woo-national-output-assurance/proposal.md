---
kind: code
depends_on: [diwoo-metadata-on-the-publication]
---

# Proposal: woo-national-output-assurance

## Summary

OpenCatalogi proves that what it serves the Woo-index and PLOOI is valid, complete and current, and a person hears when a national hand-over breaks.

- Rows: 5.19, 9.13, 9.20, 13.9.
- Wave: 2.
- Depends on: `opencatalogi/diwoo-metadata-on-the-publication` (https://github.com/ConductionNL/opencatalogi/issues/1753) for the stored DiWoo fields and the XSD fixture. Without integriq the hand-overs answer unreachable, and this change raises an incident for that.
- Decision: none of D1 to D13. REQ-WND-003 is amended.
- Build rules: openspec/woo-build-rules.md

## Why

The Woo-index and PLOOI are where the law's active disclosure becomes visible nationally. Today OpenCatalogi serves them, but cannot say whether what it serves is right, complete and current, and when something breaks nobody hears.

Rows, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **5.19** "A change to a published record reaches every public output without the officer publishing again". Ours: partial, production. Evidence: "lib/Listener/PlooiDeliveryListener.php queues a PLOOI delivery only when a publication has just become public, so a later change never reaches PLOOI".
- **9.13** "The product validates its own public metadata output against the standard's schema as it generates it". Ours: partial, production. Evidence: "lib/Service/WooReadinessService.php::checkDiwooXsd validates a rendered sample after the fact against the schema location our own renderer declares, so it validates late and against a version we chose. Nothing validates at generation time".
- **9.20** "The harvest sitemaps list every published document, checked against the register, and a newly published one appears without anyone acting". Ours: partial, production. Evidence: "Nothing checks the sitemap against the register: no count, reconciliation or completeness report exists".
- **13.9** "A pipeline failure reaches someone by email or chat". Ours: partial, production. Evidence: "opencatalogi has zero IMailer or INotification hits, so a failure in the publication pipeline reaches the log and not a person".

Read on development at 35999c296. `PlooiDeliveryListener::handle()` returns early unless the old object was not public and the new one is (REQ-WND-003). `StandardsVersionService::DIWOO_VERSION` declares 0.9.8 and `DIWOO_METADATA_XSD` the matching XSD; nothing asks the standard's own site which version is current. `SitemapService::collectDiwooViolations()` lists value-list violations, not schema validity.

## What changes

- A change to a publication that PLOOI already holds, in a DiWoo field or in its set of public documents, queues a fresh delivery: an update of the metadata and documents, and a withdrawal of documents that left. A depublication queues a withdrawal. This amends REQ-WND-003.
- Every `diwoo:Document` is validated against the DiWoo XSD as the sitemap page is rendered, using the copy `diwoo-metadata-on-the-publication` keeps under `tests/fixtures/diwoo/` and ships in `lib/Settings/diwoo/`. An invalid document is left out of the page and reported. A daily job asks the standard's authority (standaarden.overheid.nl) which DiWoo version is current and fails the readiness check when ours is behind.
- A daily reconciliation counts, per catalogue and category, the published Woo publications and public documents in the register against the entries in the DiWoo sitemap pages, and reports missing and extra entries in the readiness report.
- Every pipeline failure (readiness failing, a sitemap or category refused, a batch publish failing, a national hand-over failing, a reconciliation gap, a stale standard) creates a `wooPipelineIncident` object. Its schema declares an OpenRegister notification rule that sends a Nextcloud notification and an e-mail to the group in `woo_incident_group`.

## Fail closed

- An invalid document is never served to the harvester. It is left out and reported, so one bad record does not make the whole page refused.
- When the authority cannot be reached, the version check reports "could not verify", never "current".
- A reconciliation that cannot read the register or a page reports "incomplete check", never "complete".
- When no incident group is set, the readiness check fails with "nobody receives pipeline failures". Failures are still recorded as incidents.
- A re-delivery failure sets `plooiStatus` to failed with the reason and raises an incident; the public record stays as it is (REQ-WND-003 does not block publishing).

## Out of scope

- The DiWoo fields themselves (`diwoo-metadata-on-the-publication`).
- Registration with the Woo-index (REQ-WIH-003).
- Upgrading to a newer DiWoo version. This change detects that one exists.

## Dependencies

- `diwoo-metadata-on-the-publication` (opencatalogi, planned, wave 1): the stored DiWoo fields and the XSD fixture.
- OpenRegister on development: `x-openregister-notifications` with `created` trigger and the `email` and `nc-notification` channels (`AnnotationNotificationDispatcher`).
- integriq (for PLOOI and the gateway): without it, hand-overs already answer unreachable (`IndexUnreachableException`); this change raises an incident for that, so it is not silent.

## Wave

Wave 2. It validates the fields wave 1 adds.

## Decisions

None of D1 to D13 is implemented here. REQ-WND-003 is amended (quoted in full under `## MODIFIED Requirements`).

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 5.19 | A change to a published record reaches every public output without the officer publishing again | partial | REQ-WNO-001, scenario "A corrected title reaches PLOOI without publishing again" |
| 9.13 | The product validates its own public metadata output against the standard's schema as it generates it | partial | REQ-WNO-002, scenarios "An invalid document is held back at render time" and "A newer DiWoo version is noticed" |
| 9.20 | The harvest sitemaps list every published document, checked against the register | partial | REQ-WNO-003, scenario "A document missing from the sitemap is reported" |
| 13.9 | A pipeline failure reaches someone by email or chat | partial | REQ-WNO-004, scenario "A failed PLOOI delivery reaches the Woo team" |
