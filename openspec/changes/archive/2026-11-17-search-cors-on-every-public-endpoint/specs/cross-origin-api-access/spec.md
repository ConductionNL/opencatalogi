---
status: proposed
---

# CORS on the search and federation endpoints

## ADDED Requirements

### Requirement: The search and federation endpoints answer cross-origin calls (REQ-SCO-001)

`GET /api/search` with its item, attachments, download, uses and used endpoints, and `GET /api/federation/publications` with the same five, SHALL answer an `OPTIONS` preflight and SHALL send `Access-Control-Allow-Origin` on every answer, resolved from the allowlist by the shared rule. They SHALL send `Vary: Origin` and `Access-Control-Allow-Credentials: false`.

#### Scenario: A municipality's website searches from the browser

- **GIVEN** the allowlist holds `https://www.voorbeeldgemeente.nl`
- **WHEN** a reader's browser on that site calls `GET /index.php/apps/opencatalogi/api/search?_search=begroting`
- **THEN** the answer carries `Access-Control-Allow-Origin: https://www.voorbeeldgemeente.nl`
- **AND** the browser shows the results on the municipality's page

#### Scenario: A site that is not on the list

- **GIVEN** the allowlist holds only `https://www.voorbeeldgemeente.nl`
- **WHEN** a browser on `https://elders.example` sends a preflight for `/api/federation/publications`
- **THEN** the answer does not name `https://elders.example`, so the browser blocks the call

### Requirement: One rule decides the allowed origin (REQ-SCO-002)

Every public controller that sends CORS headers SHALL resolve the allowed origin through the shared `AnswersCrossOriginRequests` rule. No controller SHALL read the allowlist setting itself.

#### Scenario: The catalogue list follows the shared rule

- **GIVEN** the allowlist holds two origins and a request comes from the second
- **WHEN** a browser calls `GET /api/catalogi`
- **THEN** the answer names the second origin, exactly as `GET /api/search` does for the same request

### Requirement: The administrator edits the list of allowed websites (REQ-SCO-003)

The admin settings SHALL show the websites that may call the public API, one per line, where `*` means any website. Saving SHALL refuse a line that is not a bare origin (scheme, host and optional port) and SHALL name that line.

#### Scenario: An administrator adds the municipality's website

- **GIVEN** an administrator on the publishing section of the OpenCatalogi admin settings
- **WHEN** they enter `https://www.voorbeeldgemeente.nl` and save
- **THEN** a browser on that site can call the search

#### Scenario: A line with a path

- **GIVEN** the same screen
- **WHEN** the administrator enters `https://www.voorbeeldgemeente.nl/zoeken` and saves
- **THEN** the save is refused and the message names that line
