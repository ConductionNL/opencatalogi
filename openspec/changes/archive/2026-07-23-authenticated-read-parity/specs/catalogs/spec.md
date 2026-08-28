## ADDED Requirements

### Requirement: Authenticated callers receive full object metadata on public reads (CAT-AUTH-001)

Public read endpoints that assemble catalog/publication envelopes MUST make
the `@self` metadata stripping session-aware. For requests with no
authenticated Nextcloud session, the response MUST remain byte-identical to
the current anonymous envelope (the fixed strip list: `schemaVersion`,
`relations`, `locked`, `owner`, `folder`, `application`, `validation`,
`retention`, `size`, `deleted`). For requests carrying an authenticated
session, the full `@self` metadata MUST be passed through unmodified for every
object the caller may read. Which objects are returned MUST NOT differ between
the two audiences beyond what OpenRegister RBAC (`_rbac: true`) already
decides — this requirement changes envelope richness only, never visibility.

#### Scenario: anonymous envelope is unchanged

- GIVEN a published catalog readable anonymously,
- WHEN an unauthenticated caller lists it via the public API,
- THEN each object's `@self` MUST omit the stripped properties,
- AND the envelope MUST be byte-identical to the pre-change baseline.

> @e2e exclude Backend envelope contract; anonymous baseline byte-parity is asserted by PHPUnit against a golden fixture, no distinct UI surface.

#### Scenario: authenticated caller sees full metadata

- GIVEN the same catalog and an authenticated user whom OR RBAC allows to read it,
- WHEN that user lists it via the same public API route,
- THEN each object's `@self` MUST include `owner`, `locked`, `retention` and
  the other previously stripped properties as provided by OpenRegister.

> @e2e exclude Backend envelope contract; covered by PHPUnit with a mocked IUserSession; no rendering change ships in this change.

#### Scenario: session changes metadata richness, never the object set

- GIVEN an identical OR RBAC context for an anonymous and an authenticated request,
- WHEN both list the same catalog,
- THEN both responses MUST contain the same object ids in the same order,
- AND only the `@self` richness may differ.

> @e2e exclude Backend parity contract; PHPUnit.
