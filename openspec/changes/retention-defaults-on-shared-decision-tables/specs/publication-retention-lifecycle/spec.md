# publication-retention-lifecycle (delta)

## MODIFIED Requirements

### Requirement: Per-catalog retention defaults per WOO information category (RET-004)
Admin settings MUST allow configuring, per catalog, a default
`{retentionTermMonths, retentionAction}` per WOO information category (the 17
categories of WOO-003) plus a catalog-wide fallback. When a publication is
first published, the matching default MUST be applied to any retention field
the officer left empty; already-set values MUST NOT be overwritten, and
defaults changed later MUST NOT retroactively alter existing publications.
Retention terms MUST NOT be hard-coded in PHP.

The category-to-default match itself MUST be evaluated by OpenRegister's
shared decision-table evaluator
(`OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`), not by app-local
matching code (hydra ADR-065, One Engine). The stored defaults are translated
per catalog into a decision table under the `FIRST` hit policy — one
quoted-literal rule per configured category in configured order, the
catalog-wide fallback as a trailing `-` catch-all rule — so the observable
outcome (which default a publication receives) is identical to the previous
in-app matching. A `no_rule_matched` evaluation MUST leave the publication
unchanged, and an unavailable evaluator MUST be logged and treated as "no
defaults configured", never guessed around with a second matcher.

#### Scenario: Default applied at publication time
- GIVEN catalog "vergunningen" configures category "vergunningen" with
  `{ termMonths: 12, action: "depublish" }`
- WHEN a publication in that category is published without retention fields
- THEN the publication MUST carry `retentionTermMonths: 12`,
  `retentionAction: "depublish"`, and a computed `retentionExpiresAt`

> @e2e exclude Unchanged carried-over RET-004 scenario — this delta changes the
> matcher behind it, not the observable behaviour; the outcome is verified by
> PHPUnit `RetentionServiceTest::testApplyDefaultsFillsEmptyButNeverOverwrites`
> now running through the shared evaluator, and the surface (publication save)
> has no new UI.

#### Scenario: Officer override wins over the default
- GIVEN the same catalog default
- WHEN a publication is published with `retentionTermMonths: 60` already set
- THEN the value 60 MUST be preserved

> @e2e exclude Unchanged carried-over RET-004 scenario; only-fill-empties is a
> server-side rule with no distinct UI surface, verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsFillsEmptyButNeverOverwrites`.

#### Scenario: Changing a default is not retroactive
- GIVEN existing publications created under the old default of 12 months
- WHEN the admin changes the category default to 24 months
- THEN existing publications MUST keep their stored retention values

> @e2e exclude Unchanged carried-over RET-004 scenario; non-retroactivity is a
> data-at-rest property (stored values are simply never re-stamped), verified
> by PHPUnit `RetentionServiceTest`.

#### Scenario: Category match runs through the shared evaluator
- GIVEN a catalog with defaults for categories "vergunningen" and
  "beschikkingen" plus a `_fallback` row
- WHEN a publication in category "beschikkingen" is stamped at publication
  time
- THEN the resolution MUST be produced by
  `DecisionTableEvaluator::evaluate()` over a `FIRST`-policy table in which
  each category is a quoted-literal input cell and `_fallback` is the last,
  `-` catch-all rule
- AND the "beschikkingen" rule's outputs MUST win over the fallback

> @e2e exclude Internal delegation contract with no HTTP- or UI-observable
> surface of its own (the observable outcome is the unchanged RET-004
> behaviour above); verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsResolvesSpecificCategoryThroughEvaluator`
> asserting against the real evaluator grammar (or its signature-identical CI
> stub).

#### Scenario: No matching rule leaves the publication untouched
- GIVEN a catalog whose defaults configure only category "vergunningen" and
  no `_fallback`
- WHEN a publication in category "onbekend" is stamped
- THEN the evaluator's `no_rule_matched` outcome MUST leave every retention
  field as the officer left it
- AND no error MUST surface to the publishing flow

> @e2e exclude Server-side exception-mapping contract with no UI surface;
> verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsWithoutMatchingRuleLeavesPublicationUnchanged`.

#### Scenario: An unavailable evaluator degrades to "no defaults", loudly
- GIVEN OpenRegister's evaluator cannot be resolved from the container
- WHEN a publication is stamped at publication time
- THEN the publication MUST be returned unchanged
- AND a warning MUST be logged naming the unavailable evaluator
- AND no app-local fallback matcher MUST be consulted

> @e2e exclude Availability-guard contract only reachable in bare test/CLI
> contexts (the live path is an OR event listener, so OR is present);
> verified by PHPUnit
> `RetentionServiceTest::testApplyDefaultsLogsWhenEvaluatorUnavailable`.
