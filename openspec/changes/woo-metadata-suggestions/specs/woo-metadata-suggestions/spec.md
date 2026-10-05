---
status: proposed
---

# Woo metadata suggestions

## ADDED Requirements

### Requirement: Missing Woo metadata is proposed from data the instance holds (REQ-WMS-001)

When a publication lacks its information category, publisher organisation or handling type, the app SHALL propose a value from data it already holds and SHALL record the source and a reason with it. Every proposed value SHALL resolve against the TOOI and DiWoo value lists; a value that does not resolve SHALL NOT be proposed. A field that already has a value SHALL get no proposal.

#### Scenario: A synchronised record arrives without a category

- **GIVEN** a publication created by a synchronisation in a Woo catalogue schema for category 2.1 and no category set
- **WHEN** an editor opens the publication page
- **THEN** a pending suggestion for the category shows the value, the source rules and the reason

#### Scenario: A field someone already set

- **GIVEN** a publication whose handling type an editor set to vaststelling
- **WHEN** the rules floor runs for that publication
- **THEN** no suggestion for the handling type is created

### Requirement: Hermiq may add suggestions, never replace them (REQ-WMS-002)

An editor SHALL be able to ask Hermiq for suggestions from the text of the publication's documents. Hermiq's suggestions SHALL only add to the rules floor, SHALL be checked against the same value lists, and SHALL be marked with the source hermiq. Without Hermiq, the action SHALL not be offered.

#### Scenario: An editor asks Hermiq

- **GIVEN** Hermiq is installed and a publication lacks a summary and a category
- **WHEN** the editor presses Ask Hermiq on the publication page
- **THEN** new pending suggestions appear marked hermiq, and the rules suggestions stay as they were

#### Scenario: Hermiq is not installed

- **GIVEN** Hermiq is not installed
- **WHEN** an editor opens the publication page
- **THEN** no Ask Hermiq action is shown

### Requirement: Nothing is written until a person accepts (REQ-WMS-003)

A suggestion SHALL change the publication only when a person with the right to update that publication accepts it. Accepting SHALL write the value and record who accepted it and when. Rejecting SHALL leave the publication unchanged. A user without update rights SHALL be refused.

#### Scenario: An editor accepts a category

- **GIVEN** a pending category suggestion on a publication
- **WHEN** the editor presses Accept
- **THEN** the publication's category holds the value
- **AND** the suggestion shows accepted with the editor's name and the time

#### Scenario: A reader without update rights

- **GIVEN** a user who can read but not update the publication
- **WHEN** they call `POST /api/metadata-suggestions/{id}/accept`
- **THEN** the call is refused and the publication is unchanged

## ADDED Requirements

Amendment 2026-10-05, Woo capability programme, rows 13.19, 14.1 and 14.2.

### Requirement: A field with one lawful value for this officer fills itself (REQ-WMS-004)

When a publication is created, `OCA\OpenCatalogi\Service\Woo\SingleLawfulValue::fill(array $publication, IUser $officer): array` SHALL compute, for `organization`, the catalogue and `wooCategory`, the set of values this officer may lawfully choose: the organisations the officer is a member of (OpenRegister's organisation membership), the catalogues whose schemas include the publication's schema and that the officer may write to, and the categories `WooCategoryRegistry` declares for the publication's schema. When a field is empty and its set has exactly one member, it SHALL fill the field and record `filledByRule: [{field, value, rule, at}]` on the publication. When a set is empty, has more than one member, or cannot be computed, it SHALL fill nothing for that field. The publication page SHALL label such a field "Filled automatically: the only value you can choose". A pre-save listener on `ObjectCreatingEvent` for the publication schema SHALL call it.

#### Scenario: An officer of one organisation does not pick it

- **GIVEN** an officer who is a member of one organisation only, in a schema one category declares
- **WHEN** the officer creates a publication without an organisation or category
- **THEN** both are filled and the page labels them as filled automatically

#### Scenario: Two choices, no fill
<!-- @e2e exclude Service rule; proven by SingleLawfulValueTest::testTwoLawfulValuesFillNothing, which fails on today's code because no such service exists. -->

- **GIVEN** an officer who is a member of two organisations
- **WHEN** a publication is created without an organisation
- **THEN** `organization` stays empty

#### Scenario: The set cannot be computed
<!-- @e2e exclude Fail-closed path; proven by SingleLawfulValueTest::testAnUncomputableSetFillsNothing. -->

- **GIVEN** a membership lookup that throws
- **WHEN** a publication is created
- **THEN** nothing is filled and the save goes on

### Requirement: Suggestions are made when a document is uploaded (REQ-WMS-005)

Gated on decision D10: build only once Ruben keeps row 14.1.

When a file is attached to a publication and Hermiq is installed, the app SHALL queue the `source=hermiq` suggestion path of REQ-WMS-002 for that publication, at most once per file, without an editor pressing Ask Hermiq. The suggestions SHALL stay pending until accepted (REQ-WMS-003).

#### Scenario: An upload leads to suggestions

- **GIVEN** Hermiq installed and a draft publication without a category
- **WHEN** an editor uploads a document to it
- **THEN** pending Hermiq suggestions appear without the editor asking

### Requirement: A citizen summary is suggested in plain language, labelled as AI-made (REQ-WMS-006)

Gated on decision D10: build only once Ruben keeps row 14.2.

The Hermiq path SHALL be able to suggest `summary` written at language level B1 from the publication and its documents. The suggestion and, once accepted, the publication page SHALL carry the label "Written with AI, checked by <editor>". It SHALL be stored only when an editor accepts it (REQ-WMS-003).

#### Scenario: A B1 summary is suggested

- **GIVEN** Hermiq installed and a publication without a summary
- **WHEN** an editor asks Hermiq
- **THEN** a summary suggestion labelled as AI-made appears, and nothing is stored until the editor accepts it

## MODIFIED Requirements

### Requirement: Nothing is written until a person accepts (REQ-WMS-003)

A suggestion SHALL change the publication only when a person with the right to update that publication accepts it. Accepting SHALL write the value and record who accepted it and when. Rejecting SHALL leave the publication unchanged. A user without update rights SHALL be refused. The one exception is a field with exactly one lawful value for the officer, filled by rule at create time and labelled so (REQ-WMS-004, decision D5); a Hermiq suggestion is never filled without acceptance.

#### Scenario: An editor accepts a category

- **GIVEN** a pending category suggestion on a publication
- **WHEN** the editor presses Accept
- **THEN** the publication's category holds the value
- **AND** the suggestion shows accepted with the editor's name and the time

#### Scenario: A reader without update rights

- **GIVEN** a user who can read but not update the publication
- **WHEN** they call `POST /api/metadata-suggestions/{id}/accept`
- **THEN** the call is refused and the publication is unchanged

#### Scenario: A Hermiq suggestion with one possible value is still not filled
<!-- @e2e exclude Service rule; proven by SingleLawfulValueTest::testAHermiqSuggestionIsNeverAutoFilled. -->

- **GIVEN** a Hermiq suggestion for a field
- **WHEN** no editor has accepted it
- **THEN** the field is unchanged
