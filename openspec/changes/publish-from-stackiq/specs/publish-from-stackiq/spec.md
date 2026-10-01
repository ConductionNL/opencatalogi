# publish-from-stackiq Specification Delta

**Status**: proposed
**Scope**: opencatalogi
**OpenSpec changes**:
- [publish-from-stackiq](../../)

## Purpose

An organisation that keeps its application landscape in stackiq publishes it through OpenCatalogi, without looking up ids, and without publishing anything stackiq keeps private.

## ADDED Requirements

### Requirement: REQ-PFS-001 OpenCatalogi seeds an unpublished Applicatielandschap catalogue over stackiq

OpenCatalogi SHALL seed catalogue `applicatielandschap` with register `stackiq` and schemas `module`, `suite`, `catalogService`, `connection` and `usage`, named by slug. The seed SHALL NOT carry a `published` date. The import backfill SHALL set `published` once, the first time the scope resolves to ids only. The seed SHALL NOT name `catalogContract`, `contactPerson`, `aiSystem` or `technologyComponent`.

#### Scenario: The seed names stackiq's real slugs and nothing stackiq keeps private
@e2e exclude A register fragment with no browser surface; tests/Unit/Settings/PublishFromStackiqSeedTest.php reads it.

- **GIVEN** `lib/Settings/register.d/publish-from-stackiq.json`
- **WHEN** its catalogue seed is read
- **THEN** `registers` is `["stackiq"]` and `schemas` lists the five application-level slugs
- **AND** it has no `published` key
- **AND** none of `catalogContract`, `contactPerson`, `aiSystem`, `technologyComponent` appears in it

#### Scenario: Without stackiq the catalogue publishes nothing
@e2e exclude Checked live on :8096 against the anonymous API; there is no page to drive.

- **GIVEN** OpenCatalogi installed and stackiq not installed
- **WHEN** an anonymous visitor searches OpenCatalogi
- **THEN** the Applicatielandschap catalogue is not listed
- **AND** no result comes from its scope

#### Scenario: The catalogue is published once stackiq resolves
@e2e exclude Checked live on :8096 by reading the catalogue after stackiq is enabled.

- **GIVEN** the seeded catalogue without `published`
- **WHEN** the backfill resolves `stackiq` and all five schema slugs
- **THEN** the catalogue has a `published` date of that moment
- **AND** a later backfill leaves that date alone

### Requirement: REQ-PFS-002 A schema slug resolves only inside the catalogue's own registers

When a catalogue scope names schemas by slug and at least one of its registers resolves to an id, a schema slug SHALL resolve only to a schema that one of those registers lists. A slug no such register lists SHALL stay a slug. This SHALL hold in the import backfill and in the pre-save catalogue listener.

#### Scenario: Another app's schema with the same slug is not taken
@e2e exclude Resolution logic; tests/Unit/Service/CatalogScopeSlugResolverTest.php and tests/Unit/Service/CatalogiServiceTest.php.

- **GIVEN** register `stackiq` (id 20) lists schema 33 with slug `organization`
- **AND** schema 9, also with slug `organization`, belongs to register `publication`
- **WHEN** a catalogue with registers `["stackiq"]` and schemas `["organization"]` is resolved
- **THEN** its schemas become `["33"]`

#### Scenario: A slug outside the catalogue's registers stays a slug
@e2e exclude Resolution logic; tests/Unit/Service/CatalogScopeSlugResolverTest.php.

- **GIVEN** register `stackiq` does not list a schema with slug `usage` yet
- **WHEN** the catalogue is resolved
- **THEN** `usage` stays in the scope as a slug
- **AND** the register id of `stackiq` is reported as pending

### Requirement: REQ-PFS-003 Excluded stackiq fields never reach an anonymous visitor

OpenCatalogi SHALL NOT add a field filter of its own. Which stackiq objects and fields an anonymous visitor sees SHALL follow stackiq's object read rules and OpenRegister property-level read rules, on OpenCatalogi's search, its public publication API and its facets alike.

#### Scenario: A published application shows its public fields only
@e2e exclude Checked live on :8096 against the anonymous API with real stackiq objects; recorded in the PR body.

- **GIVEN** the Applicatielandschap catalogue is published
- **AND** a stackiq `module` with `publicationDate` in the past, a `contactPerson` and `usages`
- **AND** stackiq declares `contactPerson` and `usages` readable for `authenticated` only
- **WHEN** an anonymous visitor searches OpenCatalogi and reads the module through the public API
- **THEN** the module is found
- **AND** neither response contains `contactPerson` or `usages`
- **AND** a facet over `contactPerson` returns no buckets

#### Scenario: An unpublished application and never-public schemas stay hidden
@e2e exclude Checked live on :8096 against the anonymous API; recorded in the PR body.

- **GIVEN** a stackiq `module` without a `publicationDate` and a `catalogContract`
- **WHEN** an anonymous visitor searches OpenCatalogi
- **THEN** neither appears

### Requirement: REQ-PFS-004 Installing stackiq after OpenCatalogi completes the scope

The backfill SHALL record which register slugs and ids a catalogue scope still waits for. When OpenRegister creates or updates a register that is pending, OpenCatalogi SHALL run the backfill again. For any other register event it SHALL do nothing beyond reading that record.

#### Scenario: stackiq enabled after OpenCatalogi
@e2e exclude Checked live on :8096 by enabling stackiq after OpenCatalogi; tests/Unit/Listener/CatalogScopePendingListenerTest.php covers the trigger.

- **GIVEN** OpenCatalogi installed and the Applicatielandschap scope still on slugs
- **WHEN** stackiq is enabled
- **THEN** the scope holds stackiq's register id and the ids of the five schemas
- **AND** OpenCatalogi's configuration was not imported again

#### Scenario: An unrelated register event does nothing
@e2e exclude Listener logic; tests/Unit/Listener/CatalogScopePendingListenerTest.php.

- **GIVEN** the pending record names `stackiq` only
- **WHEN** register `publication` is updated
- **THEN** the backfill does not run
