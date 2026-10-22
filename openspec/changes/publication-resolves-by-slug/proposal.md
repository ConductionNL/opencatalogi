---
kind: code
depends_on: []
---

# Proposal: publication-resolves-by-slug

## Why

A public publication URL hangs on the object's UUID (`/api/{catalogSlug}/{uuid}`). A
re-import, re-provision or reset-sync gives the publication a new UUID, so every link
anyone published breaks (#1592). OpenRegister now gives an object a slug from its
schema's `objectSlugField`, unique within its register and schema and frozen once set
(openregister#4037). For WOO publications that slug is built from `kenmerk`, the case
number from the source (Robert Zondervan, 2026-10-19), so it survives a re-import and a
title change.

OpenCatalogi does not use it yet. Measured on 2026-10-22 against a catalogue on the
NC 35 rig: `GET /api/{catalogSlug}/{uuid}` answers 200 with `@self.slug` set, and
`GET /api/{catalogSlug}/{slug}` answers 404.

## What Changes

- `GET /api/{catalogSlug}/{id}` resolves `{id}` as a UUID first, exactly as today, and
  when that finds nothing, as a slug within the catalogue's registers × schemas.
- A slug held by more than one publication in the catalogue (two schemas of one
  catalogue may share a `kenmerk`; OpenRegister only guarantees uniqueness per register
  and schema) is refused: the endpoint answers 404 and logs a warning naming the
  catalogue, the slug and the matching UUIDs. It never returns the first match.
- The admin detail route `#/publications/{catalogSlug}/{id}` is not changed: it loads
  through OpenRegister's object API, not this endpoint (see design D4).
- Nothing else changes: UUID links, the response shape, federation and directory links
  (they keep the UUID), sitemap and schema.org output (out of scope, Robert 2026-10-19).

Deliberate behaviour change: a slug URL that matches nothing today answers 404 and
keeps doing so; a slug URL that matches one publication now answers 200.

## Capabilities

### New Capabilities

- none

### Modified Capabilities

- `publications`: PUB-002 resolves a publication by UUID **or** slug within its
  catalogue; new requirements for the ambiguous case and the backwards-compatibility
  rules.

## Impact

- `lib/Controller/PublicationsController.php` (`show()`), possibly a helper in
  `lib/Service/PublicationQueryService.php`.
- No route change: `{id}` in `appinfo/routes.php` has no format requirement.
- `src/views/publications/PublicationDetail.vue`: not changed; it does not use this
  endpoint (design D4).
- Depends at runtime on openregister#4037 (slugs that are unique and frozen) and on
  `objectSlugField: kenmerk` in openwoo-app-config for WOO publications. On an older
  OpenRegister, publications have no slug and the endpoint behaves as today.
- Website side (woo-website-template-apiv2 linking on the slug, with the UUID as
  fallback) is a separate issue.
