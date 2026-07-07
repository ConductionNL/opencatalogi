## ADDED Requirements

### Requirement: opencatalogi MUST NOT issue raw SQL against OpenRegister storage internals

opencatalogi MUST NOT construct raw SQL that references OpenRegister's internal
storage layout — the magic tables named `oc_openregister_table_{register}_{schema}`
or the `information_schema` catalog used to probe their existence. Cross-schema /
multi-magic-table publication search (today hand-rolled as a raw `UNION ALL` in
`lib/Service/PublicationQueryService.php:172-270`, with an `information_schema`
existence probe in `magicTableExists()`) MUST be obtained through an OpenRegister
search API (`ObjectService` / `zoeken-filteren` cross-schema search over the
catalog's configured register/schema pairs).

The post-query visibility guard `isObjectPublic()` (based on
`publicatiedatum`/`depublicatiedatum`) MUST be preserved unchanged — only the
retrieval mechanism changes, not the visibility semantics. The `IDBConnection`
injection and the `magicTableExists()` helper MUST be removed once the OR search
call replaces them.

#### Scenario: cross-schema relation search goes through OR

- **GIVEN** a public per-catalog relation request (`/uses` or `/used`) spanning a
  catalog with multiple register/schema pairs,
- **WHEN** `PublicationQueryService` gathers candidate rows,
- **THEN** it MUST call an OpenRegister search API over the catalog's configured
  register/schema pairs,
- **AND** it MUST NOT execute any SQL referencing `oc_openregister_table_*` or
  `information_schema`,
- **AND** the `isObjectPublic()` visibility filter MUST still be applied to the
  results.

#### Scenario: no raw OR-storage SQL remains

- **GIVEN** the change is implemented,
- **WHEN** `lib/` is scanned,
- **THEN** no source file MUST contain the string `oc_openregister_table_` or a
  reference to `information_schema`,
- **AND** `PublicationQueryService` MUST NOT inject `IDBConnection`.

### Requirement: opencatalogi observability metrics MUST be sourced from OR object aggregation

opencatalogi's metrics provider MUST obtain its counts through OpenRegister
object aggregation (the aggregation surface already consumed by
`UsageCounterService`/`StatsController`), not through raw `IDBConnection` query
builders against OpenRegister tables. The emitted metric values MUST be identical
to the current output.

#### Scenario: metrics counts come from OR aggregation

- **GIVEN** the observability metrics endpoint is scraped,
- **WHEN** `OpenCatalogiMetricsProvider` computes its counts,
- **THEN** it MUST use OpenRegister object aggregation rather than raw query
  builders against OR tables,
- **AND** the returned metric values MUST match the pre-change values for the
  same data.
