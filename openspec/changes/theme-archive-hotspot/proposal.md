---
kind: code
depends_on: [subjects-as-first-class-records, openregister/appraisal-inherited-from-a-parent]
---

# Proposal: theme-archive-hotspot

## Why

An archive hotspot (archiefhotspot) is a subject of such public weight that everything about it is kept permanently, whatever the selection list says for each record on its own. The Archiefwet selection practice lets an organisation designate one; once it does, records already filed under the subject must be kept too. OpenCatalogi cannot express this.

Row, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`:

- **11.14** "Marking a subject as an archive hotspot keeps every publication under it permanently". Ours: no. Evidence: "nothing marks a theme or subject as an archive hotspot: #theme carries title, summary, description, image, content, link, url, icon, isExternal and sort only. Nearest: openregister lib/BackgroundJob/DestructionCheckJob.php:245 spares an object whose own archiefnominatie is bewaren (Appraisal::RETAIN_PERMANENTLY_ALIASES), per object rather than inherited from a subject".

Read on development at 35999c296. Two things decide whether a publication goes away. OpenRegister's `DestructionCheckJob` reads the object's own `@self.retention.archiefnominatie`. OpenCatalogi's own `RetentionService::evaluate()` acts on each publication's `retentionAction` (`review`, `depublish`, `archive`), from defaults set per catalogue and information category (RET-004). A hotspot has to hold against both, for publications filed before and after the marking.

## What changes

- A subject gains `archiveHotspot` (boolean) with `archiveHotspotReason` and `archiveHotspotSince`. Marking one takes a reason and is audited; only an administrator may mark or unmark.
- A marked subject's own OpenRegister retention carries `archiefnominatie: blijvend_bewaren`. The publication schema declares `x-openregister-retention.inheritAppraisalFrom: ["themes"]`, so OpenRegister's destruction check holds back every publication under a hotspot, existing and future, without writing to any publication (`openregister/appraisal-inherited-from-a-parent`).
- OpenCatalogi's `RetentionService::evaluate()` takes no retention action (no depublish, no archive) on a publication under a hotspot, and reports it as held with the subject named.
- The publication page and the retention report show "Kept permanently: archive hotspot <subject>".

## Fail closed

- When OpenCatalogi cannot read a publication's subjects, `evaluate()` takes no action on it in that run and reports it. Held is the safe state.
- OpenRegister's side holds an object back when a declared ancestor cannot be resolved (REQ-AIP-002 of the OpenRegister change).
- Unmarking a hotspot does not release anything by itself: publications under it go back to their own appraisal, which the next destruction list shows for a person to approve. Unmarking needs a reason and is audited.

## Out of scope

- The OpenRegister inheritance itself: `openregister/appraisal-inherited-from-a-parent`.
- Changing retention defaults. RET-004 keeps governing the defaults; a hotspot is its own rule beside them (decision D5).
- Transfer to an e-Depot.

## Dependencies

- `openregister/appraisal-inherited-from-a-parent` (OpenRegister, planned in this programme, wave 1, supporting). Contract this side needs, as that change specifies it (REQ-AIP-001): a schema declares `x-openregister-retention.inheritAppraisalFrom` as a list of reference property names; OpenRegister's `RetentionService` resolves them up to five levels before an object is judged eligible for destruction; an ancestor with an appraisal in `Appraisal::RETAIN_PERMANENTLY_ALIASES` holds the object back and the report names it. That change's scenario names the property `subjects`; on the OpenCatalogi publication it is `themes`, and this change declares `themes`.
- `subjects-as-first-class-records` (opencatalogi, planned, wave 1): adds to the same theme schema; this change bumps the version after it.

## Wave

Wave 2. It needs the OpenRegister inheritance from wave 1.

## Decisions

- D5, row 11.14: "Row wins outside the defaults mechanism: a hotspot is its own rule reaching publications already filed. RET-004 keeps governing defaults." Implemented as written. RET-004 is not modified; this adds a requirement beside it.

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 11.14 | Marking a subject as an archive hotspot keeps every publication under it permanently | no | REQ-THA-001, REQ-THA-002 and REQ-THA-003, scenarios "A publication filed before the marking is kept" and "OpenCatalogi's own retention leaves it alone" |
