# Tasks: dcat-ap-harvest

This change is a read-only rendering layer over existing published objects
(hydra ADR-022): no new storage, no new visibility rules, no new broadcast
machinery.

## Task 1: Implementation planning
- **Spec ref**: specs/dcat-ap-harvest/spec.md
- **Status**: todo
- **Acceptance criteria**: Requirements decomposed into implementable tasks
  respecting the derive-don't-store split; DCAT-AP-NL version pin and
  validation depth (checklist vs SHACL) decided with the user.

## Task 2: DCAT mapping layer (`x-dcat` + defaults)
- **Spec ref**: specs/dcat-ap-harvest/spec.md — DCAT-004, DCAT-005
- **Status**: todo
- **Acceptance criteria**:
  - `DcatMappingService` resolves the property map from the schema's `x-dcat`
    annotation, falling back to the built-in default mapping; `"x-dcat": false`
    opts out. No per-schema PHP mapping.
  - Mandatory-property completion chain: object → catalog config defaults →
    owning Organisation; TOOI URIs for publisher/theme when configured.

## Task 3: Serializer + public endpoints
- **Spec ref**: specs/dcat-ap-harvest/spec.md — DCAT-001, DCAT-002, DCAT-003,
  DCAT-006, DCAT-007
- **Status**: todo
- **Acceptance criteria**:
  - `GET /api/dcat` and `GET /api/catalogs/{catalogSlug}/dcat` public routes,
    CORS per cross-origin-api-access, registered in routes.php with auth
    posture annotations.
  - Dataset selection delegates to OR object search with the catalog's
    register/schema filter and the `@self.published` visibility predicate —
    byte-for-byte the PUB-001 rule, no DCAT-local visibility logic.
  - JSON-LD (default), Turtle, RDF/XML via Accept + `?format=`; 406 on
    unsupported formats; identical graph across serializations.
  - Dataset IRI = canonical PUB-002 URL; distribution downloadURL = PUB-007 /
    download-service URL; stable across requests.

## Task 4: Pagination + caching
- **Spec ref**: specs/dcat-ap-harvest/spec.md — DCAT-008
- **Status**: todo
- **Acceptance criteria**:
  - `hydra:PagedCollection` paging at 1000 entries/page (same ceiling as
    WOO-005), no duplicates/gaps across pages.
  - `Last-Modified`/`ETag` + conditional-GET `304`; generation is
    streaming/iterative (no full-catalog in-memory graph).

## Task 5: Federation directory advertisement
- **Spec ref**: specs/dcat-ap-harvest/spec.md — DCAT-009
- **Status**: todo
- **Acceptance criteria**:
  - Listing schema gains optional `dcatEndpoint`; DirectoryService populates
    it for `hasDcat: true` catalogs on serve and broadcast.
  - No new broadcast channel/cron; retry/dead-letter untouched
    (FED-OR-001/002).

## Task 6: Admin settings + validation
- **Spec ref**: specs/dcat-ap-harvest/spec.md — DCAT-010
- **Status**: todo
- **Acceptance criteria**:
  - Per-catalog `hasDcat` toggle (default off) + publisher/license/
    contactPoint defaults in the existing admin-settings surface.
  - "Validate DCAT feed" action reports mandatory-property violations per
    dataset IRI; advisory only (never blocks serving).

## Task 7: Tests + docs
- **Spec ref**: specs/dcat-ap-harvest/spec.md (all)
- **Status**: todo
- **Acceptance criteria**:
  - Newman collection covering DCAT-001..010 (API-only surface — per the
    Playwright-UI-only/Newman-API rule); PHPUnit for mapping/fallback chains.
  - README updated so the DCAT-AP claim points at a real, documented surface;
    harvester onboarding note (URL to register at data.overheid.nl).
