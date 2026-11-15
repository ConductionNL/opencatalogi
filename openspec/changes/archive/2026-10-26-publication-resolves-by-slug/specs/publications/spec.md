## MODIFIED Requirements

### Requirement: Retrieve a single publication by catalog slug and object UUID or slug (PUB-002)

The system MUST retrieve a single publication by catalog slug and an identifier that is
either the object's UUID or the object's slug. The identifier MUST first be resolved as
a UUID within the catalogue's registers and schemas, exactly as before. Only when that
finds no publication MUST it be resolved as a slug within the same registers and
schemas. A publication outside the catalogue's registers and schemas MUST NOT be
returned by either route.

(Previously: the identifier was resolved as a UUID only; a slug answered 404.)

**Priority:** Must **Status:** In progress

#### Scenario: Get a single publication by UUID

- GIVEN a publication with UUID "xyz-789" within a catalog
- WHEN a GET request is made to `/api/{catalogSlug}/xyz-789`
- THEN the matching publication MUST be rendered and returned

#### Scenario: Get a single publication by slug

- GIVEN a publication with slug "2026-0042" within catalog "woo"
- WHEN a GET request is made to `/api/woo/2026-0042`
- THEN that publication MUST be rendered and returned with HTTP 200
- AND the response MUST have the same shape as a lookup by its UUID

#### Scenario: The URL id is normalised before the slug lookup

- GIVEN a publication stored with slug "zaak-2026-0042" within catalog "woo"
- WHEN a GET request is made to `/api/woo/Zaak_2026-0042`
- THEN the identifier MUST be normalised like OpenRegister normalises a stored slug (lowercase, spaces and underscores to hyphens, other characters outside a-z, 0-9 and hyphen dropped, hyphen runs collapsed, edge hyphens trimmed, cut to 255) before the slug lookup
- AND that publication MUST be returned with HTTP 200
- AND an identifier with nothing left after normalising MUST NOT run a slug search (HTTP 404)

#### Scenario: Slug of a publication in another catalog

- GIVEN a publication with slug "2026-0042" in catalog "archive" only
- WHEN a GET request is made to `/api/woo/2026-0042`
- THEN the response MUST be HTTP 404

#### Scenario: Unknown slug

- GIVEN no publication in catalog "woo" holds slug "does-not-exist"
- WHEN a GET request is made to `/api/woo/does-not-exist`
- THEN the response MUST be HTTP 404 with the existing "Publication not found" body

## ADDED Requirements

### Requirement: A slug held by more than one publication in a catalogue MUST be refused (PUB-019)

The system MUST NOT return a publication for a slug that matches more than one publication across the catalogue's registers and schemas. It MUST answer HTTP 404 with the
existing "Publication not found" body, and MUST log a warning that names the catalogue,
the slug and the UUIDs of the matching publications. The response MUST NOT contain those
UUIDs.

#### Scenario: Two schemas of one catalogue share a slug

- GIVEN catalog "woo" spans schemas "convenanten" and "adviezen"
- AND a publication in each holds slug "2026-0042"
- WHEN a GET request is made to `/api/woo/2026-0042`
- THEN the response MUST be HTTP 404
- AND a warning MUST be logged naming catalog "woo", slug "2026-0042" and both UUIDs
- AND the response body MUST NOT contain either UUID

#### Scenario: The UUID of one of them still resolves

- GIVEN the two publications above
- WHEN a GET request is made to `/api/woo/{uuid of the first}`
- THEN that publication MUST be returned with HTTP 200

### Requirement: Slug resolution MUST be backwards compatible (PUB-020)

Resolving by slug MUST NOT change any behaviour for a UUID URL, for a publication without a slug, or for a catalogue without registers and schemas.

#### Scenario: UUID URL unchanged

- GIVEN a publication reachable at `/api/{catalogSlug}/{uuid}` before this change
- WHEN the same URL is requested after this change
- THEN the response MUST be identical in status and body

#### Scenario: A numeric slug is never treated as an object id

- GIVEN a publication whose slug is "123456" and another object with numeric id 123456 in the same register
- WHEN a GET request is made to `/api/{catalogSlug}/123456`
- THEN the publication holding slug "123456" MUST be returned
- AND the object with numeric id 123456 MUST NOT be returned through the slug route

#### Scenario: Publication without a slug

- GIVEN a publication without a slug (its source has no `kenmerk`, or it was not backfilled)
- WHEN it is requested by its UUID
- THEN it MUST be returned as before

#### Scenario: Catalogue without registers or schemas

- GIVEN a catalogue with no registers or no schemas configured
- WHEN any identifier is requested
- THEN the response MUST be what it was before this change (no fallback search)
