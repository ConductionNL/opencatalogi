# Tasks: retention-defaults-on-shared-decision-tables

## 1. Delegate the RET-004 match

- [x] 1.1 Add `RetentionService::getDecisionTableEvaluator()` — container
      resolution of `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator` with
      the same cached, logged availability guard as `getObjectService()`
- [x] 1.2 Add `RetentionPolicyTable::fromDefaults(array $catalogDefaults)`
      (own class, keeps RetentionService under the PHPMD class-length cap) —
      FIRST-policy table, quoted-literal category rules in configured order,
      trailing `-` catch-all when `_fallback` is configured, non-array rows
      skipped, missing keys as null output entries
- [x] 1.3 Rewrite the matching branch of `applyDefaults()` to call the
      evaluator; map `no_rule_matched` to "return unchanged", other
      `DecisionEvaluationException`s and an unresolvable evaluator to a logged
      warning + unchanged; delete the in-app category/`_fallback` lookup
- [x] 1.4 `@spec` tags on the new/changed methods pointing at
      `openspec/changes/retention-defaults-on-shared-decision-tables/specs/publication-retention-lifecycle/spec.md`

## 2. Test scaffolding

- [x] 2.1 Add `tests/Stubs/OpenRegister/Service/Dmn/{DecisionTableEvaluator,UnaryTestEvaluator,DecisionEvaluationException}.php`
      as functional signature copies of OR development (provenance noted,
      OR-repo `@spec` tags stripped)
- [x] 2.2 Point `RetentionServiceTest`'s container mock at a per-class
      resolver (fake ObjectService for ObjectService, a real
      `DecisionTableEvaluator` instance for the Dmn class)

## 3. Tests

- [x] 3.1 Existing RET-004 tests stay green through the delegated path
      (default applied, officer override preserved, expiry computed)
- [x] 3.2 New: specific category beats `_fallback` through the evaluator
      (`testApplyDefaultsResolvesSpecificCategoryThroughEvaluator`)
- [x] 3.3 New: no matching rule and no `_fallback` leaves the publication
      unchanged (`testApplyDefaultsWithoutMatchingRuleLeavesPublicationUnchanged`)
- [x] 3.4 New: unresolvable evaluator logs a warning and returns unchanged
      (`testApplyDefaultsLogsWhenEvaluatorUnavailable`)
- [x] 3.5 New: a category containing a double quote still matches (escaping
      round-trips through the evaluator's unquote)

## 4. Quality

- [x] 4.1 phpcs / phpmd (per subdirectory) / psalm / phpstan run individually
      and green on the touched files
- [ ] 4.2 Hydra gates `--scope-to-diff` green against
      `origin/development`
