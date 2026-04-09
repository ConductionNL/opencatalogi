# Tasks: org-archimate-export

## 1. XML Generation

- [x] 1.1 Generate valid AMEFF XML with organization applications as ApplicationComponent elements
- [x] 1.2 Create SpecializationRelationship linking applications to referentiecomponenten
- [x] 1.3 Copy views with applications plotted inside referentiecomponenten
- [x] 1.4 Use Titel view SWC property for view naming
- [x] 1.5 Support query parameters for toggling modules/gebruik/deelnames layers

## 2. Folder Structure

- [x] 2.1 Organize output into typed folders (Applicaties, Relaties, Views)
- [x] 2.2 Include Bron property on application elements

## 3. Unit Tests (ADR-009)

- [x] 3.1 Test export produces well-formed XML
- [x] 3.2 Test organization with no applications exports base GEMMA only

## 4. Documentation (ADR-010)

- [x] 4.1 Feature documentation at docs/features/org-archimate-export.md

## 5. Internationalization (ADR-005)

- [x] 5.1 Export UI labels translatable in nl/en
