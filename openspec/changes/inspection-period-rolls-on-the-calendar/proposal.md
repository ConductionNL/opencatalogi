---
kind: code
depends_on: [openregister/end-date-roll-on-the-calendar]
---

# Proposal: inspection-period-rolls-on-the-calendar

## Why

The Algemene termijnenwet (Atw), article 1 paragraph 1, extends a term set by law that ends on a Saturday, a Sunday or a generally recognised holiday to the next working day; article 3 names those holidays. A public inspection period (terinzagelegging) is such a term. OpenCatalogi already rolls the end of a comment period this way (REQ-PCP-002, through `TermRoll`). It does not roll the end of an inspection period, and it does not roll an end date someone writes through the API.

Row, from `opencatalogi/_round1/compare/M1-rows.md`, with our column from `baseline/openwoo.tsv`. This change supports the row; `dossiq/woo-term-is-computed-and-reported-right` closes it for the Woo decision term:

- **10.9** (statutory) "A term's end date obeys the Algemene termijnenwet without anyone computing it, including through the API". Ours: partial, build. Evidence: "openregister lib/Service/Calendar/DeadlineDateResolver.php rolls a term off a non working day for any flow timer, which is the Atw behaviour. It is not applied to an inspection period on a publication because no such record exists, and nothing rolls a date supplied through an API field". The evidence is out of date in one respect: the `inspection` schema exists (`publication-inspection-and-the-national-indexes`, 29 of 29 tasks done), and `InspectionService::open()` computes `endDate` as `start + P{termDays}D` with no roll.

## What changes

- `InspectionService::open()` computes the end through `TermRoll::endDate()`, as `CommentPeriodService` does, and stores `unrolledEndDate` and `rolledBy` when the roll moved it.
- `TermRoll` gains `roll(DateTimeInterface $at, ?string $calendarSlug, ?string $organisation): array` for a date that is already given.
- A pre-save listener on the `inspection` and `commentPeriod` schemas rolls an `endDate` written through any API (OpenRegister's object API included) the same way, and records where it landed before the roll.
- REQ-PIN-103 is modified to say the end is rolled.

## Fail closed

- When the term engine cannot be reached, opening an inspection is refused and an API write of an end date is refused, with `TermRollUnavailableException`'s message. A statutory end date is never stored unrolled because the engine was down. This matches REQ-PCP-002.
- A rolled end date is never moved earlier: the roll goes to the next working day only.

## Out of scope

- The Woo decision term on a request. That is dossiq's (`dossiq/woo-term-is-computed-and-reported-right`, decision D1).
- Calendars per organisation beyond what `TermRoll` already resolves.

## Dependencies

- `openregister/end-date-roll-on-the-calendar` (OpenRegister, open change outside this plan, 5 of 6 tasks; the open task is a Newman run). `TermRoll` already uses the OpenRegister calendar and calculator it builds on (`calculator()->add()`, `calculator()->roll()`); this change needs nothing beyond what is on development, and waits on it only so its last task does not change the behaviour underneath.
- `dossiq/woo-term-is-computed-and-reported-right` (dossiq, planned, wave 1) is the change that closes 10.9 for the decision term.

## Wave

Wave 2, as the plan places it, after the OpenRegister change completes.

## Decisions

- D1: the decision term belongs to dossiq; this change rolls only OpenCatalogi's own terms (inspection and comment period).

## Rows

| row | text | rating today | what makes it yes |
|---|---|---|---|
| 10.9 | A term's end date obeys the Algemene termijnenwet without anyone computing it, including through the API | partial | supports: REQ-IPR-001 and REQ-IPR-002, scenarios "An inspection ending on a Sunday ends on Monday" and "An end date written through the API is rolled"; the row is yes once dossiq's decision term also rolls |
