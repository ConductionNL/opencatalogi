---
kind: code
depends_on: [diwoo-metadata-on-the-publication]
---

# Proposal: diwoo-metadata-on-the-publication-filinq-handoff

## Summary

filinq writes the DiWoo document type of a handed-off Woo record into the publication's `documentsoort` field, never into `summary`, and both sides test that contract.

- Rows: supports 2.25 for publications that filinq hands off; the row itself closes in `diwoo-metadata-on-the-publication`.
- Wave: 2. Split off `diwoo-metadata-on-the-publication` on 2026-10-06 to keep each part at 20 tasks or fewer.
- Depends on: `opencatalogi/diwoo-metadata-on-the-publication` (https://github.com/ConductionNL/opencatalogi/issues/1753), which adds the field, the documentsoorten route and the completeness listener. Paired with `filinq/diwoo-documentsoort-to-opencatalogi` (https://github.com/ConductionNL/filinq/issues/1344), which writes the field.
- Decision: D8 (filinq's documentsoort workaround in `summary` ends; the field is written instead).
- Build rules: openspec/woo-build-rules.md

## Why

filinq hands a ready Woo record to OpenCatalogi and, because the publication had no field for it, writes the DiWoo documentsoort into `summary` (filinq REQ-DDWPP-005; code `lib/Service/Publication/OpenCatalogiPublicationMap.php::toPublication()`, `'summary' => (string) ($record['documentsoort'] ?? '')`). The type is lost to the sitemap, and the summary of every handed-off publication reads `besluit` or similar. `diwoo-metadata-on-the-publication` adds the `documentsoort` field and moves existing values out of `summary` with a repair step. What remains is the contract for new handoffs: a dynamic cross-app save compiles even when the other side writes the wrong key, so each side needs a test of it.

## What changes

- OpenCatalogi tests that the exact payload filinq's `toPublication()` will produce (`title`, `publicationDate`, `documentsoort` as code, label or URI, no `summary`) is accepted, normalised to the URI, and that an unknown type is refused (REQ-DWP-006).
- OpenCatalogi asks the filinq lane, by issue or comment, for the matching test on filinq's side.

## Fail closed

- A document type outside the national documentsoorten list is refused, never stored as free text. filinq surfaces that refusal to the operator as REQ-DDWPP-005 already requires for an OpenRegister failure.

## Dependencies

- `diwoo-metadata-on-the-publication` (opencatalogi, wave 1).
- filinq absent: nothing here changes. OpenCatalogi absent: filinq's handoff stays disabled with its explanation (REQ-DDWPP-005).

## Wave

Wave 2, one wave after the change it splits from.

## Decisions

- D8: implemented as written, for the handoff contract.
