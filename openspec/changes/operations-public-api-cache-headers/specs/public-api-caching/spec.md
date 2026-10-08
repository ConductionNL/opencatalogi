---
status: proposed
---

# Public API caching

## ADDED Requirements

### Requirement: Anonymous answers of the public API may be cached (REQ-PAC-001)

A successful anonymous answer of the publication list, a publication, its attachments list, the public search and a search result SHALL carry `Cache-Control: public` with the configured max-age and `must-revalidate`, an `ETag` over the answer as sent, and `Vary: Origin, Accept-Language`. A request whose `If-None-Match` matches SHALL get 304 Not Modified without a body. Error answers SHALL carry no public cache header.

#### Scenario: A CDN caches a catalogue page

- **GIVEN** a cache time of 60 seconds
- **WHEN** an anonymous reader calls `GET /index.php/apps/opencatalogi/api/woo-publicaties`
- **THEN** the answer carries `Cache-Control: public, max-age=60, must-revalidate`, an `ETag` and `Vary: Origin, Accept-Language`

#### Scenario: Nothing changed

- **GIVEN** a reader who received an `ETag` for a publication
- **WHEN** they request it again with that value in `If-None-Match` and the publication did not change
- **THEN** the answer is 304 without a body

#### Scenario: An unknown catalogue

- **GIVEN** no catalogue with the slug `nope`
- **WHEN** an anonymous reader calls `GET /api/nope`
- **THEN** the 404 answer carries no public cache header

### Requirement: Answers to a signed-in user stay private (REQ-PAC-002)

An answer of the same endpoints to a signed-in user SHALL carry `Cache-Control: private, no-store` and no `ETag`, because it can hold records the public cannot read.

#### Scenario: An editor searches

- **GIVEN** an editor signed in to Nextcloud
- **WHEN** the editor calls `GET /api/search?_search=begroting`
- **THEN** the answer carries `Cache-Control: private, no-store`

### Requirement: The administrator sets the cache time (REQ-PAC-003)

The admin settings SHALL offer the cache time in seconds, default 60. Zero SHALL switch the public cache headers off.

#### Scenario: An administrator switches caching off

- **GIVEN** an administrator on the publishing section of the OpenCatalogi admin settings
- **WHEN** they set the cache time to 0 and save
- **THEN** an anonymous `GET /api/{catalogSlug}` answer carries no public cache header
