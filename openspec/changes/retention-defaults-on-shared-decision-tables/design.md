# Design: retention-defaults-on-shared-decision-tables

## Context

OpenRegister `development` ships a shared DMN surface under
`lib/Service/Dmn/`:

- `DecisionTableEvaluator::evaluate(array $decisionTable, array $inputs):
  array{outputs: array, matchedRuleIds: array, hitPolicy: string}` — hit
  policies `UNIQUE|FIRST|COLLECT|PRIORITY|ANY`, positional
  `inputEntries`/`outputEntries`, unary-test cell grammar.
- `UnaryTestEvaluator` — cell grammar: `-`/empty catch-all, quoted literal
  (`"..."`, with `\"` escapes) for exact match, bare literal equality,
  comparisons, ranges, `in (...)` sets.
- `DecisionEvaluationException` — `getErrorCode()` in `unknown_input`,
  `missing_input`, `type_mismatch`, `invalid_expression`, `no_rule_matched`,
  `hit_policy_violation`, `hit_policy_not_implemented`.

The evaluator is a pure, stateless class ("directly constructible … while the
Nextcloud container autowires the concrete class when resolved via DI"), so a
non-flow caller consumes it the way this app already consumes
`ObjectService`: `ContainerInterface::get()` with an availability guard. No
open OR PR is needed — everything used here is merged (#3186, #3191, #3329;
checked 2026-09-02).

## Decision 1: translate at evaluation time, persist nothing new

The stored `retention_defaults` app-config JSON
(`{catalogSlug: {category: {termMonths, action}, _fallback: {...}}}`) remains
the single persisted form. `RetentionService` builds the decision-table array
on each `applyDefaults()` call:

```php
[
    'hitPolicy' => 'FIRST',
    'inputs'    => [['name' => 'category', 'type' => 'string']],
    'outputs'   => [
        ['name' => 'termMonths', 'type' => 'number'],
        ['name' => 'action', 'type' => 'string'],
    ],
    'rules'     => [
        // one per configured category, in configured order:
        ['id' => 'cat:<category>', 'inputEntries' => ['"<category, \" escaped>"'],
         'outputEntries' => [<termMonths|null>, <action|null>]],
        // when `_fallback` is configured, LAST:
        ['id' => '_fallback', 'inputEntries' => ['-'],
         'outputEntries' => [<termMonths|null>, <action|null>]],
    ],
]
```

Why quoted literals: a bare cell starting with `<`, `>`, `=`, `in (` or
equal to `-` would parse as an operator; quoting makes every category an
exact-match cell regardless of its spelling. Why FIRST: the current code
prefers the exact category over `_fallback`; specific rows first + catch-all
last under FIRST reproduces that ordering exactly. An empty
`retentionCategory` matches only the catch-all, which is today's behaviour
(empty category → `_fallback`).

Rows whose value is not an array are skipped when building the table — the
same tolerance `applyDefaults()` has today. A missing `termMonths`/`action`
key becomes a `null` output entry, and null outputs are skipped when filling,
preserving the per-key fill semantics.

This makes the change idempotent with no migration and no dual-read: the
definition store never changes shape, only the matcher does.

## Decision 2: exception mapping preserves the current outcomes

| Evaluator outcome | Current equivalent | Behaviour |
| --- | --- | --- |
| outputs returned | rule found | fill empty fields from non-null outputs |
| `no_rule_matched` | no category row, no `_fallback` | return publication unchanged |
| any other `DecisionEvaluationException` | (new) | log warning, return unchanged |
| evaluator unresolvable (OR absent) | OR absent already skips retention work | log warning, return unchanged |

Fail-safe here is not a silent no-op risk: `applyDefaultsAtPublication()` is
invoked from OR object-event listeners, so OR is definitionally present on
the only live path; the guard exists for bare test/CLI contexts, and it logs.

## Decision 3: stubs are verbatim signature copies

`tests/bootstrap-unit.php` prefers a sibling `openregister/lib` checkout and
falls back to `tests/Stubs/OpenRegister/`. The three Dmn classes are pure PHP
with no OCP imports, so the stubs are functional copies of the real files
(license header noting provenance, `@spec` tags pointing at OR's repo removed
so the local spec-anchor gate does not chase cross-repo paths). Signatures
match OR `development` at 2839ab901 by construction. This keeps the delegated
grammar actually exercised in bare CI instead of encoding this caller's
expectations into a hand-written fake.

## Risks

- OR grammar drift: a future OR change to the unary-test grammar changes
  matching here. Accepted — that is the point of consolidation; the fleet
  moves together.
- Category spellings containing `"` are escaped (`\"`), which the evaluator's
  `unquote()` reverses; covered by a unit test.
