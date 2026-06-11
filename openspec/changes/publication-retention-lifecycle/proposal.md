# Proposal: publication-retention-lifecycle

## Summary
Give publications a complete **time dimension**: scheduled (embargoed)
publication, statutory retention metadata per WOO information category
(Archiefwet 2021 / selectielijsten), automatic depublish-or-review on expiry,
and an auditable archive state. Today the publications spec covers
publish/depublish as instantaneous, hand-triggered actions (PUB-016/017); the
lifecycle schema already declares an `archived` state (APB-SM-001) but nothing
ever moves a publication there, nothing records *why* or *until when* a
publication should remain public, and nothing helps a publication officer act
when a term expires. For a government publication platform this is a statutory
gap: WOO information categories carry retention expectations, and the
Archiefwet requires substantiated, auditable retention and disposal decisions.

Per **hydra ADR-022**, this change builds NO scheduler, NO state machine, and
NO audit store:

- **Scheduling** uses the timestamps OR already evaluates: `@self.published`
  and `@self.depublished` are date-times, and APB-006 already derives public
  visibility from them — a future `@self.published` *is* an embargo and a
  future `@self.depublished` *is* a scheduled depublication. This change makes
  those semantics explicit, surfaced, and testable instead of accidental.
- **State transitions** (`published → archived`) are declared in the schema's
  existing `x-openregister-lifecycle` (APB-SM-001); no PHP transition guards.
- **Retention metadata** is a thin schema layer (retention category, term,
  disposal action, review date) on the publication schema — stored in OR like
  every other field.
- **Expiry detection** is one scheduled evaluation pass (the only in-app
  moving part, mirroring the existing directory-sync cron pattern); the
  resulting notifications go through schema-declared
  `x-openregister-notifications` (ADR-031, building on the
  `opencatalogi-notifications` change) and side effects through
  workflow-integration triggers.
- **Decision audit** consumes the OR immutable audit-trail abstraction.

## Motivation
Publication officers currently must remember to depublish by hand. That fails
in both directions: content that legally must come down (expired permits,
personal-data-bearing WOO documents past their term) stays public, and content
that must stay findable is deleted ad hoc without a disposal record. Every
serious records-management or WOO platform (and the Archiefwet itself) demands
"publish at T, keep until T+termijn, then review/archive/dispose — with a
paper trail". Embargoed publication (besluiten that become public at a legal
effective date, persberichten at 09:00) is the same mechanism pointed at the
other end of the timeline, and we get it almost for free from the
published-predicate semantics.

## Scope
- Explicit scheduled publication (future `@self.published`) and scheduled
  depublication (future `@self.depublished`) end-to-end: UI date pickers,
  public-surface invisibility until/after the moment, sitemap + DCAT + feed
  coherence.
- Retention metadata fields on the publication schema: retention category
  (per WOO information category / selectielijst entry), retention term,
  disposal action (`review` / `depublish` / `archive`), computed
  `retentionExpiresAt`, optional legal-basis note.
- Per-catalog retention defaults per WOO information category (the 17
  categories of WOO-003), applied at publication time, overridable per object.
- Daily expiry evaluation: expiring/expired publications flagged, notified
  (schema-declared notifications), and — when so configured — automatically
  depublished or transitioned to `archived` via the lifecycle declaration.
- Review queue for publication officers (expiring-soon list + dashboard
  widget) with explicit "extend / depublish / archive" decisions.
- Disposal/retention decision audit via the OR audit-trail abstraction +
  exportable retention report (CSV) for Archiefwet accountability.

## Out of scope (consumed, not built)
- Visibility evaluation from published/depublished timestamps — OR
  (published-predicate, APB-006).
- State-machine mechanics — `x-openregister-lifecycle` (APB-SM-001).
- Notification dispatch — schema-declared `x-openregister-notifications`
  (ADR-031) / workflow-integration.
- Audit immutability — OR audit-trail abstraction.
- Physical transfer to an e-Depot / archival system (TMLO/MDTO export
  pipelines) — future change; this change produces the decision trail and the
  archive state such an export would consume.
- File deletion mechanics — NC Files / OR file handling.

## References
- hydra ADR-022 (consume OR abstractions), ADR-031 (notification dialect).
- Archiefwet (retention/disposal duties), WOO information categories
  (woo-compliance WOO-003), VNG selectielijst gemeenten.
- Existing specs: `publications` (PUB-016/017/018), `auto-publishing`
  (APB-006, APB-SM-001), `woo-compliance` (sitemap coherence), `dashboard`,
  `admin-settings`; in-flight change `opencatalogi-notifications`.
