---
status: proposed
---

# Woo-index harvester connection

## ADDED Requirements

### Requirement: robots.txt names every Woo sitemap index on its own line (REQ-WIH-001)

The app's robots.txt at `GET /api/robots.txt` SHALL contain one `Sitemap:` line per category sitemap index of each catalogue whose `hasWooSitemap` is true, and no line for any other catalogue. Every line SHALL end in a line break character, never in the two characters backslash and n. The file SHALL allow the app's API paths, so a crawler that honours robots may fetch the sitemaps and the documents they list.

#### Scenario: Two Woo catalogues and one other

- **GIVEN** catalogues `woo-a` and `woo-b` with `hasWooSitemap` true and catalogue `news` with it false
- **WHEN** a harvester requests `GET /index.php/apps/opencatalogi/api/robots.txt`
- **THEN** the response lists the sitemap indexes of `woo-a` and `woo-b`, each on its own line
- **AND** it lists nothing for `news`
- **AND** it contains no literal backslash-n

#### Scenario: The sitemap paths are allowed

- **GIVEN** one Woo-enabled catalogue
- **WHEN** a harvester reads the app's robots.txt
- **THEN** it finds an `Allow:` line covering `/apps/opencatalogi/api/`

### Requirement: The administrator gets the rule that serves robots.txt at the domain root (REQ-WIH-002)

The Woo section of the admin settings SHALL show a ready Apache rule and a ready nginx rule that serve the app's robots.txt at `/robots.txt` on the instance's base URL. When the readiness check reports the root robots.txt as unreachable or without a sitemap line, the panel SHALL point at these rules.

#### Scenario: An administrator copies the nginx rule

- **GIVEN** an administrator on the Woo section of the OpenCatalogi admin settings
- **WHEN** they open the root robots.txt rule
- **THEN** they see an nginx `location = /robots.txt` block that targets the app's robots.txt route on their own base URL
- **AND** a copy button puts it on the clipboard

#### Scenario: A failing check points at the rule

- **GIVEN** a readiness report whose `robots-txt` check failed with `missing-sitemap-reference`
- **WHEN** the administrator opens the Woo section
- **THEN** the failed check says the root robots.txt does not reach the app and links to the rules

### Requirement: The Woo-index registration is requested through the gateway (REQ-WIH-003)

An administrator SHALL request the Woo-index registration with one action. The app SHALL compose the request from the organisation's name and TOOI identifier, the root robots.txt URL and the sitemap index URLs of every Woo-enabled catalogue, and hand it to the gateway's national Woo-index channel. The registration status SHALL move to `requested` only when the gateway answered, and the answer SHALL be stored with the time. When no gateway can take the request, nothing SHALL be recorded as sent, and the composed request SHALL be shown so the administrator can send it another way.

#### Scenario: The gateway takes the request

- **GIVEN** integriq is installed with a `national-woo-index` source
- **WHEN** an administrator presses Request registration in the Woo section
- **THEN** the status shows requested, with the time and the gateway's answer

#### Scenario: No gateway is installed

- **GIVEN** no gateway app is installed
- **WHEN** an administrator presses Request registration
- **THEN** the status stays as it was
- **AND** the panel shows the composed request with the organisation, the robots.txt URL and every sitemap index URL

### Requirement: The readiness verdict stays current without anyone running it (REQ-WIH-004)

The harvester readiness check (WOO-HR-001) SHALL run once a day while at least one catalogue is Woo-enabled, and once whenever a catalogue's `hasWooSitemap` becomes true. The Woo section SHALL show when the last check ran.

#### Scenario: A catalogue is switched on

- **GIVEN** no catalogue was Woo-enabled
- **WHEN** an editor sets `hasWooSitemap` to true on a catalogue
- **THEN** a readiness report exists afterwards without anyone pressing Run check

#### Scenario: Nothing is Woo-enabled

- **GIVEN** no catalogue has `hasWooSitemap` true
- **WHEN** the daily job runs
- **THEN** it makes no outbound request and leaves the stored report unchanged
