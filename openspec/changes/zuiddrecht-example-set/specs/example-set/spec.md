## ADDED Requirements

### Requirement: OpenCatalogi ships its part of the zuiddrecht example set (REQ-ZES-001)

OpenCatalogi SHALL ship `lib/Settings/example-sets/zuiddrecht.json` in the format of OpenRegister's example-set loader. It SHALL declare a catalog with slug `zuiddrecht` over the `publication` schema of the `publication` register, themes, and between 10 and 15 publications with `status: published` and a `publicationDate` before the install moment. A publication with a `wooCategory` SHALL use a code of `tooi_waardelijsten.json`. Every date SHALL be relative to the install day. Only cast people and organisations SHALL be named.

#### Scenario: The fragment resolves against the shipped register
- **GIVEN** opencatalogi's merged register (`publication_register.json` plus `register.d/`) and the cast of openregister's `zuiddrecht.json`
- **WHEN** every placeholder of the fragment is resolved and every object is validated against its schema
- **THEN** every object SHALL satisfy its schema and no object SHALL carry a key its schema does not declare

@e2e exclude Example data, not behaviour. The loader that installs it is not merged (openregister#4603). The fragment is checked statically against the merged register; the loader's `--check` and read-back proof run it against a live instance.

#### Scenario: A visitor finds the Lindelaan publications
- **GIVEN** the set is installed
- **WHEN** an anonymous visitor searches the Woo publications for "fietspad"
- **THEN** the college decision, the council decision, the letter to the council and the contract with Installatiebedrijf Van Dam SHALL be in the results

@e2e exclude Needs the unmerged loader (openregister#4603) on a live instance; public visibility follows from the publication schema's read rule on publicationDate, which this change does not touch.

### Requirement: The Woo decision shows what was withheld (REQ-ZES-002)

The publication "Woo-besluit over de aanbesteding van het zaaksysteem" SHALL have `publicationKind: woo-besluit` and category `infocat014`. The set SHALL record two `withheldDocument` rows for it: number 4 on ground 5.1.1.c, and number 5 on grounds 5.2.1 and 5.1.2.e, with the labels of dossiq's refusal grounds list. The catalog SHALL have `showWithheld: true`.

#### Scenario: The withheld documents are listed without their content
- **GIVEN** the set is installed
- **WHEN** the Woo decision is opened in the Zuiddrecht catalog
- **THEN** the page SHALL list documents 4 and 5 as withheld, with their grounds, and SHALL show no title or text of them

@e2e exclude Rendering of withheld rows is existing behaviour (woo-decision-shows-what-was-withheld); this change only adds data for it.

### Requirement: Sanne has a dossier and a saved search (REQ-ZES-003)

The set SHALL give the cast resident `sanne` a dossier (`collection`) titled "Fietspad Lindelaan" with the college decision, the council decision and the letter to the council, and a weekly saved search for "fietspad Lindelaan" in the `zuiddrecht` catalog. Both SHALL have `owner` set to her portal subject reference, the cast `uid`.

#### Scenario: Sanne sees her dossier in Mijn Zuiddrecht
- **GIVEN** the set is installed and Sanne de Vries signs in to the portal
- **WHEN** she opens her dossiers
- **THEN** she SHALL see "Fietspad Lindelaan" with three publications

@e2e exclude Needs the unmerged loader and portaliq's sign-in for the example resident; the owner scope is opencatalogi's existing PortalContributionProvider.

### Requirement: The publications' documents ship with the fragment (REQ-ZES-004)

Every publication of the fragment SHALL have at least one document in `lib/Settings/example-sets/zuiddrecht/<publication key>/`, made by `scripts/example-sets/make-zuiddrecht-documents.py`. Every page SHALL say it is demo content. The script SHALL refuse to run when a document folder names no publication of the fragment, or a publication has no documents. The withheld documents SHALL have no file.

#### Scenario: The script and the fragment agree
- **GIVEN** the fragment and the script
- **WHEN** the script runs
- **THEN** it SHALL write one folder per publication key and exit 0, and a second run SHALL leave git clean

@e2e exclude A build script for data files, run by hand; it checks the fragment itself before it writes.
