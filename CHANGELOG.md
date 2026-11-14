# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Separate publish right** (operations-publish-rights). Only members of the Nextcloud group `opencatalogi-publishers` and administrators may change a publication's `publicationDate` or `depublicationDate`. OpenRegister enforces this through a property-level update rule in the publication schema. The publish and withdraw endpoints answer 403 naming the group, `GET /api/publications/{id}/visibility` returns `canPublish`, and the Publish now, Withdraw and Publish again buttons only show to publishers. The group is created on install and upgrade, without members. **Behaviour change:** an editor outside the group can no longer publish or withdraw; until the group has a member, only administrators can. The Woo section of the admin settings shows the group and its member count. See `docs/features/publish-rights.md`.

## [0.7.54] - 2026-07-16

### Added

- **Public full-text search endpoint** (`GET /apps/opencatalogi/api/search`) — anonymous-reachable, RBAC-filtered, returns publications **and** documents in a flat mixed envelope discriminated by `@self.schema`. Document rows carry an embedded `publication: { id, slug, title }` summary; documents with no linked publication are suppressed. Post-scoring `isObjectPublic()` visibility gate + transitive publication-visibility gate applied uniformly to all callers (WOO-506, spec `SCH-PFTS-001..007`, change archived at `openspec/changes/archive/2026-07-16-add-public-fulltext-search/`).
- Bundled `document` schema in `lib/Settings/publication_register.json` — schema-discoverable dedicated type for public-endpoint document rows.
- `SettingsService` now provisions `document_source` / `document_schema` / `document_register` app-config keys on fresh install so the search assembly resolves the document schema without manual setup (WOO-519).

### Fixed

- `PublicationQueryService::resolveDocumentPublicationSummary()` now addresses the linked publication via the nested `@self` metadata block instead of a bare `slug` schema-property filter. The bug silently dropped every document row from the mixed envelope; the endpoint returned only publications on a correctly-seeded env (WOO-530, PR #134).
- `MagicMapper::buildUnionSelectPart()` metadata-column CAST is now dialect-aware (`CAST(col AS CHAR)` on MariaDB, `col::text` on PostgreSQL). Previous PostgreSQL-only `::text` cast crashed the multi-schema search on MariaDB backends with `SQLSTATE[42000]` (WOO-520, openregister-side).

# Version: 0.0.1-featuredimoc279workflowforrele.1134.97c61c3


#### Other Changes

* [#1](https://github.com/ConductionNL/opencatalogi/pull/1): Fix Migrations for PSQL
* [#2](https://github.com/ConductionNL/opencatalogi/pull/2): finished metadata properties being null
* [#3](https://github.com/ConductionNL/opencatalogi/pull/3): Fix elastic string filter
* [#4](https://github.com/ConductionNL/opencatalogi/pull/4): Publicatie dialogs en documentatie
* [#5](https://github.com/ConductionNL/opencatalogi/pull/5): feature/DIMOC-236/Attachments-page-refactor
* [#6](https://github.com/ConductionNL/opencatalogi/pull/6): feature/DIMOC-216/drag-and-drop
* [#7](https://github.com/ConductionNL/opencatalogi/pull/7): Some fixes for broken stuff
* [#8](https://github.com/ConductionNL/opencatalogi/pull/8): dashboard statistics
* [#9](https://github.com/ConductionNL/opencatalogi/pull/9): Added publication download, for pdf or zip
* [#10](https://github.com/ConductionNL/opencatalogi/pull/10): Fix filters
* [#11](https://github.com/ConductionNL/opencatalogi/pull/11): feature/DIMOC-216/drag-and-drop
* [#12](https://github.com/ConductionNL/opencatalogi/pull/12): build hotfix
* [#13](https://github.com/ConductionNL/opencatalogi/pull/13): renamed listing to directory
* [#14](https://github.com/ConductionNL/opencatalogi/pull/14): Fix search because of joins
* [#15](https://github.com/ConductionNL/opencatalogi/pull/15): feature/DIMOC-260/download-buttons
* [#16](https://github.com/ConductionNL/opencatalogi/pull/16): added dropdown based on metadata properties
* [#17](https://github.com/ConductionNL/opencatalogi/pull/17): search on metadata
* [#18](https://github.com/ConductionNL/opencatalogi/pull/18): added catalogi filter to search
* [#23](https://github.com/ConductionNL/opencatalogi/pull/23): feature/sync-button
* [#24](https://github.com/ConductionNL/opencatalogi/pull/24): Metadata copy fixes
* [#25](https://github.com/ConductionNL/opencatalogi/pull/25): Fix statuscode field nullability
* [#26](https://github.com/ConductionNL/opencatalogi/pull/26): finished filtered metadata
* [#27](https://github.com/ConductionNL/opencatalogi/pull/27): feature/medata-external-source
* [#28](https://github.com/ConductionNL/opencatalogi/pull/28): feature/DIMOC-281/cleanup
* [#29](https://github.com/ConductionNL/opencatalogi/pull/29): Copy metadata from listing
* [#30](https://github.com/ConductionNL/opencatalogi/pull/30): fix migration
* [#31](https://github.com/ConductionNL/opencatalogi/pull/31): Cast id's

