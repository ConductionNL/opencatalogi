---
kind: code
depends_on: []
---

# Proposal: retention-defaults-on-shared-decision-tables

## Summary

Redirect the rule-matching half of RET-004 — resolving a publication's WOO
information category to a `{termMonths, action}` retention default — onto
OpenRegister's shared DMN decision-table evaluator
(`OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`, merged to OR
`development` in #3186/#3191/#3329). The One Engine fleet audit flagged
opencatalogi's app-local matrix resolution in
`RetentionService::applyDefaults()` as engine duplication: a hand-rolled
category-keyed lookup with a fallback row is exactly a DMN decision table
under the FIRST hit policy, and the fleet now ships one evaluator for that
(hydra ADR-065).

## Motivation

`RetentionService::applyDefaults()` (`lib/Service/RetentionService.php:269`)
implements its own decision semantics: exact category match, else the
`_fallback` row, else nothing. That is a two-row grammar of the shared DMN
unary-test grammar (a quoted literal per category, `-` as the catch-all), and
every app-local re-implementation is a place where the fleet's rule semantics
can drift. The consolidation contract (ADR-065, OR change
`shared-decision-table-evaluator`) is that leaf apps stop evaluating tables
themselves and hand the matching to the one evaluator.

What stays in-app is everything genuinely WOO/domain-specific:

- the persistence format of the defaults (`retention_defaults` app-config
  JSON, edited by the RET-004 admin settings UI) — unchanged, so there is no
  migration and the change is idempotent by construction;
- the only-fill-empties rule (an officer's stored choice is never
  overwritten);
- expiry computation (`computeExpiry`), the daily evaluation pass (RET-005),
  action execution, human decisions and reporting.

## What Changes

- `RetentionService` derives, per catalog, a DMN decision-table definition
  from the stored `retention_defaults` map (input: `category` string; outputs:
  `termMonths` number, `action` string; hit policy `FIRST`; one rule per
  configured category as a quoted-literal cell, the `_fallback` row last as a
  `-` catch-all) and delegates the match to OR's `DecisionTableEvaluator`,
  resolved from the server container with the same availability guard the
  service already uses for `ObjectService`.
- `no_rule_matched` maps to today's "no rule" outcome (publication returned
  unchanged); an unavailable evaluator is logged and treated as "no defaults
  configured", the same posture the daily pass already takes when OR is
  absent.
- The in-app matching branch in `applyDefaults()` is removed — not kept as a
  fallback, because a retained duplicate is the thing this change deletes.
- Unit tests exercise the delegated path through the real evaluator grammar
  (sibling OR checkout) or signature-identical stubs in bare CI
  (`tests/Stubs/OpenRegister/Service/Dmn/`), mirroring the existing
  bootstrap-unit stub pattern.

## Non-Goals

- No flow-node usage: this is a non-flow caller consuming the evaluator as a
  callable service, which OR supports (the evaluator is a pure, container-
  resolvable class; see its constructor docblock). The RET-005 daily pass
  stays an app cron — moving it onto `TriggerScheduleNode` is One Engine
  wave 5 territory, out of scope here.
- No change to the admin settings UI or the stored defaults shape.
- No new hit policies or grammar: FIRST with literal cells and one catch-all
  reproduces the current semantics exactly.

## Capabilities

### Modified Capabilities

- `publication-retention-lifecycle`: RET-004's default resolution is
  REQUIRED to be evaluated through the shared OR decision-table evaluator;
  observable behaviour (which default a publication gets) is unchanged.
