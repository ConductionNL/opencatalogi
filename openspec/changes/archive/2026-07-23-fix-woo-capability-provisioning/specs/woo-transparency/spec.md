## ADDED Requirements

### Requirement: WOO batch and assessment objects have shipped storage schemas (WOO-PROV-001)

The app MUST ship `wooBatch` and `wooAssessment` schemas in the bundled
publication register so the WOO transparency workflow has storage on a stock
install. The schemas MUST cover exactly the object shapes `WooService`
persists:

- `wooBatch`: `caseReference`, `status` (enum `in_progress` |
  `ready_for_review` | `published`), `deckBoardId`, `deckAvailable`,
  `documents` (array of assessment object references), `besluit`,
  `inventarislijst`, `documentSummary`, `publishedAt`, `publishedCount`,
  `wooPublication`, `createdAt`, `updatedAt`, `createdBy`.
- `wooAssessment`: `documentReference`, `fileName`, `fileType`, `assessment`
  (enum `te_beoordelen` | `openbaar` | `niet_openbaar` | `deels_openbaar`),
  `weigeringsgronden` (array), `redactionInstructions`,
  `anonymizedDocument`, `caseReference`, `assessedBy`, `assessedAt`.

Every property MUST carry a human-friendly English `title` and `description`.
No property that `WooService` writes may be absent from its schema, and the
schemas MUST NOT introduce properties the service never writes.

#### Scenario: a stock install exposes both WOO schemas

- GIVEN a fresh install whose register import has completed,
- WHEN the bundled publication register's schemas are listed,
- THEN a `wooBatch` schema and a `wooAssessment` schema MUST be present,
- AND every field `WooService` persists MUST exist as a property on the
  matching schema.

> @e2e exclude Bundled register-definition contract; asserted by a PHPUnit test that cross-checks the shipped register JSON against the field inventory, no browser-observable surface of its own.

### Requirement: WOO config keys are auto-configured on install and repair (WOO-PROV-002)

The register-import configuration step MUST populate `woo_register` (the
shared publication register id), `woo_batch_schema` (the `wooBatch` schema id)
and `woo_assessment_schema` (the `wooAssessment` schema id), using an explicit
key map because the WOO config-key prefixes deliberately differ from their
schema slugs — the same mechanism the OOAPI keys already use. Re-running the
import MUST be idempotent and MUST NOT clear an operator's existing values
with empty ones.

#### Scenario: install populates all three WOO keys

- GIVEN a register import whose result contains the `wooBatch` and
  `wooAssessment` schemas and the `publication` register,
- WHEN the object-type configuration step runs,
- THEN `woo_register` MUST equal the publication register id,
- AND `woo_batch_schema` MUST equal the `wooBatch` schema id,
- AND `woo_assessment_schema` MUST equal the `wooAssessment` schema id.

> @e2e exclude Backend configuration contract; covered by PHPUnit against a mocked import result.

#### Scenario: a missing schema in the import result leaves the key untouched

- GIVEN an import result that does not contain a `wooAssessment` schema,
- WHEN the object-type configuration step runs,
- THEN `woo_assessment_schema` MUST NOT be overwritten with an empty value.

> @e2e exclude Same backend contract; PHPUnit.

### Requirement: Every manifest resolve-sentinel is backed by provided initial state (WOO-PROV-003)

The system MUST guarantee that every resolve-sentinel appearing anywhere in
the app's effective manifest (base manifest plus manifest.d fragments plus
menu layout) has its config key listed in the initial-state provider's
manifest-config key set, so the frontend can substitute it before first paint.
A sentinel whose key is absent from that set MUST fail the build, because at
runtime it silently reaches the network as a literal register or schema
identifier and returns HTTP 404.

The WOO keys `woo_register`, `woo_batch_schema` and `woo_assessment_schema`
MUST be members of that key set.

#### Scenario: an unbacked sentinel fails the build

- GIVEN a manifest page whose `config` references `@resolve:some_new_key`,
- AND `some_new_key` is absent from the initial-state provider's key set,
- WHEN the unit test suite runs,
- THEN the parity test MUST fail naming `some_new_key`.

> @e2e exclude Build-time drift guard with no runtime surface; PHPUnit.

#### Scenario: the WOO page resolves to real ids

- GIVEN an install where the WOO config keys are populated,
- WHEN the SPA renders the WOO page,
- THEN the page's register/schema config MUST contain the configured numeric
  ids and MUST NOT contain any literal `@resolve:` string.

> @e2e exclude Frontend substitution is covered by the parity test plus the existing initial-state provision; a live-instance check is recorded in the change's tasks rather than as an automated e2e (the WOO surface needs a provisioned Deck leaf to be meaningfully driven).
