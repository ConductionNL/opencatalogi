# content-management

## ADDED Requirements

### Requirement: Staff keep the glossary on the Glossary page (REQ-CRG-002)

The Glossary page MUST list the glossary terms, and staff with write rights on
the glossary schema MUST be able to add a term with a title, summary,
description, external link and keywords, edit it and delete it. A saved term
MUST be served by the public glossary API without a separate publishing step.

#### Scenario: An editor adds a term

- **GIVEN** an editor on the Glossary page
- **WHEN** they add the term "Woo-verzoek" with a summary and save
- **THEN** the Glossary page lists "Woo-verzoek"
- **AND** `GET /api/glossary` returns it

#### Scenario: An editor corrects a term

- **GIVEN** the term "Woo-verzoek"
- **WHEN** an editor changes its summary on the term's page and saves
- **THEN** the public glossary API returns the new summary

#### Scenario: A reader without write rights

- **GIVEN** a signed-in user who may read but not write the glossary schema
- **WHEN** they open the Glossary page
- **THEN** the terms are listed and no add, edit or delete action is offered
