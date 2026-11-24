# Publications

## Purpose

Publications and catalogues carry their title, summary and description in more than one language, on OpenRegister's translation machinery. Design: `../../design.md`. Boards `OcPublicatieBewerken` and `OcPublicatie` on canvas 5NkFW28vZUUij43xzxHg5a.

## ADDED Requirements

### Requirement: Publication and catalogue text is translatable (REQ-PTR-001)

The `publication` and `catalog` schemas SHALL declare `title`, `summary` and `description` as `translatable: true` with `sourceLanguage` `nl`. OpenCatalogi SHALL store and read translations only through OpenRegister.

#### Scenario: The schema declares the fields
<!-- @e2e exclude Schema contract; proven by PublicationTranslationsSchemaTest::testTheThreeFieldsAreTranslatable. -->
- **WHEN** the publication register is imported
- **THEN** `title`, `summary` and `description` of both schemas are translatable with source language `nl`

### Requirement: The public API answers in the visitor's language (REQ-PTR-002)

The public publication and catalogue endpoints SHALL pass the language from `_lang` or `Accept-Language` to OpenRegister, SHALL answer each translatable field in that language when a translation exists and in Dutch when it does not, and SHALL set `Content-Language`. An invalid language tag SHALL be served in Dutch, not refused. The DCAT feed SHALL write each language present as its own language-tagged literal.

#### Scenario: An English visitor reads a partly translated publication
- **GIVEN** a public publication with an English title and summary and only a Dutch description
- **WHEN** a visitor requests it with `Accept-Language: en`
- **THEN** the title and summary are English and the description is Dutch

#### Scenario: A nonsense tag does not break the read
<!-- @e2e exclude Negotiation edge; proven by PublicationQueryServiceTest::testAnInvalidTagFallsBackToDutch. -->
- **WHEN** a visitor requests a publication with `?_lang=xx-!!`
- **THEN** the answer is 200 in Dutch

### Requirement: An editor writes a translation on the edit page (REQ-PTR-003)

The publication and catalogue edit pages SHALL offer a language switch with Dutch as source and every language enabled in the settings. In another language, saving SHALL write only the translatable fields for that language, and the other fields SHALL be read-only. An outdated translation SHALL be marked on its field.

#### Scenario: Adding an English summary
- **GIVEN** a Dutch publication and English enabled in the settings
- **WHEN** the editor switches to English, types a summary and saves
- **THEN** the English summary is stored as a translation and the Dutch summary is unchanged

### Requirement: The publication page shows what is translated (REQ-PTR-004)

The publication page SHALL show a Languages card listing each enabled language as source, complete, or with the fields that are missing or outdated, and SHALL offer to open the editor in that language.

#### Scenario: A missing description is named
- **GIVEN** a publication with English title and summary and no English description
- **WHEN** the editor opens the publication page
- **THEN** the Languages card reads "Title and summary translated, description missing" for English
