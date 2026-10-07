---
kind: code
depends_on: [publication-lifecycle-on-or]
---

# Proposal: publication-lifecycle-on-or-draft-purge

## Summary

An editor deletes a draft that was never released permanently, with its files, document references and side records, in one action that leaves one summary audit entry; anything ever released is refused.

- Rows: 5.14.
- Wave: 2. Split off `publication-lifecycle-on-or` on 2026-10-06 to keep each part at 20 tasks or fewer.
- Depends on: `opencatalogi/publication-lifecycle-on-or` (https://github.com/ConductionNL/opencatalogi/issues/1754) for the stored `draft` and `in_review` states and `firstReleasedAt`.
- Decision: none of D1 to D13.
- Build rules: openspec/woo-build-rules.md

## Why

Row, from the Woo capability comparison, with our column today:

- **5.14** "A draft record and everything on it is deleted permanently, and the deletion is logged". Ours: partial. Evidence: "openregister deletes an object and lib/Db/AuditTrail.php records it; a draft is not a distinct state in opencatalogi, so there is no draft-and-everything-on-it delete".

`publication-lifecycle-on-or` makes draft a stored state and stamps `firstReleasedAt` on the first release. With those two facts a draft that was never released can be told apart from a record that was once public, which is what makes a permanent delete safe.

## What changes

- A draft that was never released is deleted permanently with its files, document references and side records in one action, with one summary audit entry. Anything that was ever released is refused.

## Fail closed

- The permanent delete refuses when it cannot prove the draft was never released: a `firstReleasedAt` stamp, any `depublication` record for it, or an unreadable audit trail each refuse.
- The permanent delete removes nothing until it has listed everything it will remove. A failure part way leaves the publication in place and names what was already removed.

## Dependencies

- `publication-lifecycle-on-or` (opencatalogi, wave 1).
- OpenRegister on `development`: `ObjectService::deleteObject(..., bool $permanent = false)` and `AuditTrailMapper::createAuditTrail(?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext)`.

## Wave

Wave 2, one wave after the change it splits from.

## Decisions

None of D1 to D13 is implemented here.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 5.14 | A draft record and everything on it is deleted permanently, and the deletion is logged | partial | REQ-PLC-004, scenario "A draft and everything on it goes" |
