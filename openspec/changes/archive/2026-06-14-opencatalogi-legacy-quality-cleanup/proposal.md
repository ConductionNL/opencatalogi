# OpenCatalogi Legacy Quality Cleanup

## Why

The OR-abstraction audit (2026-05-03, stream 3 + the quality-gates
cleanup at session start) flagged that opencatalogi's quality gates
have legacy debt absorbed via exclude patterns. Burning these down
keeps PR diffs honest — gates catch real regressions rather than
silently absorbing already-broken code.

OpenCatalogi has 8 phpcs.xml exclude-patterns and no PHPMD or PHPStan
baseline yet. The bulk of the work is running PHPMD/PHPStan as a
unified gate for the first time and either fixing surfacing errors
outright or capturing them in a fresh baseline for follow-up burn-down.

This is a tracking change so the burn-down work can be picked up
later. It is spec-only; no code changes are proposed in this change.
The actual file-by-file work will land in follow-up PRs.

## What Changes

- Inventory and clear the 8 phpcs.xml exclude-patterns. For each
  excluded file: add proper docblocks + named-parameter call audits,
  then drop the exclude.
- Run PHPMD for the first time as a unified gate (phpmd.xml is
  configured but no baseline exists). Capture surfacing violations
  as a baseline OR fix them outright depending on volume.
- Run PHPStan for the first time as a unified gate. Same trade-off:
  baseline vs fix-outright.
- Wire phpcs/phpmd/phpstan into CI as the unified quality gate so
  future PRs cannot regress.

## Problem

Exclude-patterns exist because the audit captured legacy files that
predated the current quality conventions. Blocking every PR while
those files were normalised would freeze the repo; the agreed
compromise was to capture the debt as exclude-patterns and burn it
down deliberately.

PHPMD/PHPStan baselines don't exist yet because the gates haven't
been run as a unified `check:strict` block. The audit recommended
running them and capturing the result as a baseline before adoption
work — so cluster cleanup and adoption work can happen in parallel.

Now is the time because the per-app OR-abstraction adoption work
(Hydra ADR-022) is touching the same files. Any cluster cleanup that
happens in parallel with adoption work amortises across both efforts.

## Proposed Solution

File-by-file cleanup phased by directory cluster. Because the
exclude-pattern count is small (8), Phase 2 lists each file
individually rather than grouping into buckets. Phases 3-4 are
contingent on what surfaces when PHPMD / PHPStan run unified.

Estimated effort: 2-3 PRs over 1 sprint.

## Out of scope

- Refactoring beyond what the sniff requires
- New features (separate adoption-spec changes own those)
- Test additions (separate test-coverage spec change if needed)

## See also

- The canonical audit lives in openregister at
  `.claude/audit-2026-05-03/03-repo-hygiene.md`. OpenCatalogi
  references it from there.
- `phpcs.xml` (the legacy-debt baseline section)
- Hydra ADR-022 (apps consume OR abstractions) — quality conventions
- `composer.json` `check:strict` script (the unified gate target)
