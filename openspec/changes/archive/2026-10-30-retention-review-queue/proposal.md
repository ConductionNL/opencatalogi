---
kind: code
depends_on: []
---

# Proposal: retention-review-queue

## Why

RET-007 (`openspec/specs/publication-retention-lifecycle/spec.md`) asks for a
review queue: the publications by retention status, with per-item and bulk
decisions extend term (with a note), depublish now and archive. The backend is
there (`GET /api/retention/queue`, `POST /api/retention/publications/{id}/decision`,
`GET /api/retention/report`) and so is the dashboard widget, whose counts
link to `/apps/opencatalogi/?retention=<status>`, a page that does not exist.

Boards **OcBewaartermijnen** (the queue: tabs Expiring soon, Review needed,
Both, Archived; columns publication, retention category, expires on, what the
rule says, the term; row and selection actions Extend term, Depublish now,
Archive; the retention report) and **OcTermijnVerlengen** (the extend dialog:
extra months, a note kept in the history, each publication with its current
and its new end date).

## What changes

- A manifest page `RetentionReview` (`/retention`, admin), `type: index` over
  the publication pair, with the four tabs as quick filters on
  `retentionExpiresAt`, `retentionAction` and `status`.
- Row and selection actions `extend`, `depublish` and `archive` open
  `RetentionDecisionDialog` with the decision their id names; a header action
  downloads the retention report.
- The dialog asks for the note the backend requires (and the months for an
  extension), shows each publication with its current and new end date, and
  posts one decision per publication so each gets its own history entry. A
  refused publication is named.
- The dashboard widget's counts open the queue.

## Out of scope

- Opening the queue on the tab of the clicked count: the library's quick
  filters are not addressable from the URL.
