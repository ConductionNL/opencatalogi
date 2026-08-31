---
retrofit: true
---

# Entity TypeScript Models Specification

**Status**: done
**Scope**: opencatalogi
**OpenSpec changes**:
- retrofit-2026-05-25-entity-typescript-models

## Purpose

OpenCatalogi's frontend defines a TypeScript model for each domain object it works with — attachment, catalogi, configuration, glossary, listing, menu, organization, page, publication, publicationType and theme. Each model is a self-contained module under `src/entities/<name>/` that pairs a TypeScript type definition with an entity class that hydrates raw API responses into a typed, default-filled instance and exposes Zod-based client-side validation. No spec previously described this model layer; this retrofit documents the observed behavior. Schema standards are owned by OpenRegister server-side (ADR-011); these frontend models are a presentation/validation convenience layer over that data.

## ADDED Requirements

### Requirement: Each domain object has a typed entity class hydrated from API data (ETM-001)
The system MUST provide, for each frontend domain object, a TypeScript class (`src/entities/<name>/<name>.ts`) that implements a corresponding `T<Name>` type (`<name>.types.ts`) and whose constructor accepts a raw data object and populates all declared fields via a private `hydrate()` method. The class fields mirror the type definition so consuming components receive a fully-shaped, statically-typed instance.

#### Scenario: Construct an entity from an API response
- GIVEN a raw publication object returned by the OpenRegister API
- WHEN `new Publication(data)` is called
- THEN every field declared on `TPublication` is set on the instance
- AND the instance satisfies the `TPublication` type

### Requirement: Hydration applies defensive defaults and type coercion (ETM-002)
The entity `hydrate()` methods MUST apply safe fallbacks for missing or malformed API data: string fields default to `''`, arrays to `[]`, object/nested fields to a typed empty default, and nullable numeric references (`register`, `schema`) to `null`. Hydration MUST guard against the backend returning an array where an object is expected (`!Array.isArray(x) && x`) and MUST coerce known backend quirks (e.g. a boolean `featured` arriving as the strings `"1"` / `""`).

#### Scenario: Backend sends a boolean as a string
- GIVEN the API returns `featured: "1"`
- WHEN the entity is hydrated
- THEN `featured` is coerced to the boolean `true`
- AND when the API returns `featured: ""` it is coerced to `false`

#### Scenario: Missing nested object
- GIVEN the API omits the `anonymization` object
- WHEN the entity is hydrated
- THEN `anonymization` is set to its typed default `{ anonymized: false, results: '' }`

### Requirement: Entities expose Zod client-side validation (ETM-003)
Each entity MUST expose a `validate()` method returning a Zod `SafeParseReturnType`. The Zod schema MUST encode the field constraints (required fields, URL/ISO-639/CEFRL/datetime formats, enum membership, length limits) and MUST surface validation messages in Dutch for display in the editor UI. Validation is advisory at the client and does not replace server-side validation by OpenRegister.

#### Scenario: Validate an incomplete publication
- GIVEN a publication instance with an empty `title`
- WHEN `validate()` is called
- THEN the returned `SafeParseReturnType` has `success: false`
- AND the issue for `title` carries the Dutch message "is verplicht"

### Requirement: Each entity is a self-contained re-exported module (ETM-004)
Each `src/entities/<name>/` directory MUST contain the entity class (`<name>.ts`), its type (`<name>.types.ts`), test mock fixtures (`<name>.mock.ts`), and an `index.js` barrel that re-exports the class, type and mock. The barrel is the single import surface so consumers import from `../entities/<name>` (or the root `../entities` barrel) rather than reaching into individual files.

#### Scenario: Import an entity through its barrel
- GIVEN a component needs the publication model and its mock
- WHEN it imports from `src/entities/publication`
- THEN the `Publication` class, `TPublication` type and `mockPublication` fixture are all available from that single module

## Non-Functional Requirements

- **Type safety:** Entity classes MUST implement their declared `T<Name>` type so the compiler enforces field parity.
- **Internationalization:** Validation messages are authored in Dutch (the editor's primary audience); English support remains governed by ADR-007 for surfacing UI strings.

## Acceptance Criteria

- [x] 11 entities each ship a class implementing their `T<Name>` type
- [x] Hydration applies defaults and coerces known backend quirks
- [x] Each entity exposes a Zod `validate()` returning `SafeParseReturnType`
- [x] Each entity directory re-exports class/type/mock via `index.js`

## Notes

- **Observed, not aspirational.** `publication.ts` carries an explicit `// FIXME: remove once bug is fixed` for the string-boolean `featured` coercion — the model is compensating for a backend serialization bug. Documented as observed; the fix belongs server-side, not in this spec.
- **`publicationType` is not in the canonical object-type list.** The coverage report notes `publicationType` is not among the spec's 7 first-class object types, yet a full entity model exists for it. Documented here as observed; whether it should be promoted to a first-class type is a separate decision.
- **Validation is client-side advisory.** `validate()` is a UX convenience; authoritative validation is OpenRegister's (ADR-022). No fail-open concern — the model never persists; it only shapes data for the editor.
- Most `hydrate()` / `validate()` methods are marked `/* istanbul ignore next */`, so they are excluded from coverage metrics. Noted for the testing dashboard.
