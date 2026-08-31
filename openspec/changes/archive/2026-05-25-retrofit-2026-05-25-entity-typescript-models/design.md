# Design — entity-typescript-models (retrofit)

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.** The code already exists on `development`; this document records the design as observed.

## Context

The frontend works with raw JSON from the OpenRegister API. Rather than passing untyped objects around, OpenCatalogi wraps each domain object in a small TypeScript model that gives components a typed, default-filled, validatable instance. Eleven models exist (attachment, catalogi, configuration, glossary, listing, menu, organization, page, publication, publicationType, theme), all following one uniform shape.

## Per-entity module shape

```
src/entities/<name>/
├── <name>.types.ts   # export type T<Name> = { ... }   (the contract)
├── <name>.ts         # export class <Name> implements T<Name> { constructor → hydrate(); validate() }
├── <name>.mock.ts    # test fixtures
├── <name>.spec.ts    # vitest unit tests
└── index.js          # barrel: re-exports .ts + .types.ts + .mock.ts
```

## Behavior classes (→ REQ map)

| Class | Where | REQ |
|---|---|---|
| Typed class implementing T<Name>, constructor hydrates | `<name>.ts` | ETM-001 |
| Defensive defaults + coercion (string-bool, array-guard, null) | `<name>.ts::hydrate()` | ETM-002 |
| Zod `validate(): SafeParseReturnType` with Dutch messages | `<name>.ts::validate()` | ETM-003 |
| Self-contained re-exported module | `index.js` barrel | ETM-004 |

## Decisions (observed)

- **Hydrate-with-defaults over assume-well-formed.** Every field gets a fallback, and nested objects are guarded against the backend returning `[]` instead of `{}`. This makes components null-safe without per-component checks.
- **Client validation is advisory.** `validate()` mirrors the API contract (per the Stoplight docs linked in-code) but does not gate persistence — OpenRegister is authoritative (ADR-022). Messages are Dutch because the editor audience is Dutch.
- **Barrel-only imports.** Consumers import from the directory, never the individual files, so the file layout can change without touching call sites.

## Known issues surfaced (not fixed here)

- `publication.ts` compensates for a backend bug that serializes booleans as `"1"`/`""` (explicit `// FIXME`). The real fix is server-side.
- `publicationType` has a full model but is not in the canonical 7-object-type list (coverage-report note).
- `hydrate()`/`validate()` are `/* istanbul ignore next */`, excluded from coverage metrics.
