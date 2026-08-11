## ADDED Requirements

### Requirement: App-owned properties SHALL be renamed to English

Properties that opencatalogi owns SHALL use English identifiers derived from each
property's existing English `title`. The Dutch wording SHALL move to `l10n/nl.json` so
the Dutch interface is unchanged.

#### Scenario: A publication date is renamed from its own title

- **WHEN** `publication.publicatiedatum` carries `"title": "Publication Date"`
- **THEN** the property SHALL be named `publicationDate`
- **AND** the same rename SHALL apply to the identical property on `document`

#### Scenario: The Dutch label is preserved for Dutch users

- **WHEN** a user with a Dutch locale views a publication
- **THEN** the field SHALL still be labelled in Dutch
- **AND** the label SHALL be served from `l10n/nl.json`

### Requirement: The two senses of "besluit" SHALL NOT be collapsed onto one word

opencatalogi's `besluit` SHALL be named for the concept it holds — a Woo decision
**letter** — and SHALL NOT adopt the word used by apps that hold the decision
*instrument*. Forcing one English word onto two concepts produces a schema that no
payload satisfies.

#### Scenario: The decision letter is named for what it is

- **WHEN** `wooBatch.besluit` describes itself as the Woo decision letter content or
  reference for a batch
- **THEN** it SHALL be renamed to `decisionLetter`
- **AND** it SHALL NOT be renamed to `decision`

#### Scenario: Fleet-wide consistency is not accepted as a reason to merge the concepts

- **WHEN** another app renames its own `besluit` to `decision` or `decisionStatus`
- **THEN** opencatalogi SHALL retain `decisionLetter`
- **AND** the divergence SHALL be recorded as intentional

#### Scenario: The sense is determined from the property's description

- **WHEN** the correct English word for a statutory Dutch term is in question
- **THEN** the property's own `description` SHALL be the evidence
- **AND** the word SHALL NOT be chosen for symmetry with other apps

### Requirement: Published external vocabulary identifiers SHALL be preserved

Values, labels and URIs belonging to the TOOI and DiWoo value lists SHALL be preserved
exactly as published. Renaming them would make Woo publications non-conformant.

#### Scenario: A TOOI identifier survives the rename

- **WHEN** `TooiVocabularyService` resolves a value to an official TOOI kern identifier
- **THEN** that identifier SHALL be unchanged by this change
- **AND** the DiWoo `soortHandeling` value list SHALL be unchanged

#### Scenario: A method name is renamed without touching what it returns

- **WHEN** a Dutch-named method resolves an external vocabulary value
- **THEN** the method name SHALL be renamed to English
- **AND** the vocabulary values it returns SHALL be preserved

### Requirement: Woo concepts SHALL be internationalised rather than statute-marked

Woo-derived identifiers SHALL use international English terms rather than being
preserved as Dutch with a statute marker, because freedom-of-information duties exist
beyond the Netherlands as FOIA, Regulation 1049/2001 and the Open Data Directive.

#### Scenario: A Woo-derived property gets an international name

- **WHEN** a property derives from the Wet open overheid
- **THEN** it SHALL be given an international English name
- **AND** it SHALL NOT be preserved in Dutch on the grounds of being statutory

#### Scenario: A genuinely NL-only statutory concept is treated differently

- **WHEN** a concept has no international counterpart
- **THEN** it SHALL receive an English name plus a marker recording its statutory basis
- **AND** the distinction from the Woo case SHALL be recorded
