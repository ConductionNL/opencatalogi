---
status: proposed
---

# Maintenance commands

## ADDED Requirements

### Requirement: Routine maintenance runs from the command line (REQ-IMC-001)

The app SHALL provide `occ` commands to sync the federation directory (all directories, or one with `--url`), to broadcast this directory, and to rebuild or clear the cache of one catalogue or all of them. Each command SHALL call the same service the background job or event uses, SHALL print one line per item handled, and SHALL print the result as JSON with `--output=json`. It SHALL exit 0 on success and 1 when an item failed.

#### Scenario: An operator syncs one directory after a peer moved

- **GIVEN** an operator with shell access to the Nextcloud host
- **WHEN** they run `occ opencatalogi:directory:sync --url=https://catalogus.voorbeeldgemeente.nl/index.php/apps/opencatalogi/api/directory`
- **THEN** the command prints the listings it added or updated and exits 0

#### Scenario: A script reads the result

- **GIVEN** a deployment script
- **WHEN** it runs `occ opencatalogi:catalog:cache rebuild --all --output=json`
- **THEN** the output is a JSON array with one entry per catalogue and its result

### Requirement: Retention runs by hand, reporting first (REQ-IMC-002)

`occ opencatalogi:retention:evaluate` SHALL report which publications the retention evaluation would review, depublish or archive, without changing any, unless `--apply` is given. With `--apply` it SHALL act exactly as the daily job does.

#### Scenario: An operator checks new defaults before they bite

- **GIVEN** retention defaults an administrator changed today
- **WHEN** the operator runs `occ opencatalogi:retention:evaluate`
- **THEN** it lists the publications and the action each would get
- **AND** no publication changes

### Requirement: The Woo-index readiness check can gate a script (REQ-IMC-003)

`occ opencatalogi:woo:readiness` SHALL run the harvester readiness check, print each check with its result and reason, persist the report as the settings button does, and exit 1 when the verdict is not pass.

#### Scenario: A web-server change broke the root robots.txt

- **GIVEN** a proxy change that stopped serving the app's robots.txt at the domain root
- **WHEN** the deployment script runs `occ opencatalogi:woo:readiness`
- **THEN** the output shows the robots-txt check failed with its reason
- **AND** the command exits 1
