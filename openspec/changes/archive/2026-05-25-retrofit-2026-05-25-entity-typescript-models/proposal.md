# Retrofit — entity-typescript-models

Describes observed behavior of 11 frontend TypeScript entity models as 4 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units
- src/entities/attachment/* (class + types + mock + index barrel — ETM-001..004)
- src/entities/catalogi/*
- src/entities/configuration/*
- src/entities/glossary/*
- src/entities/listing/*
- src/entities/menu/*
- src/entities/organization/*
- src/entities/page/*
- src/entities/publication/*
- src/entities/publicationType/*
- src/entities/theme/*

## Approach
- For each entity module: describe observed structure (class implementing T<Name>, constructor → hydrate(), validate() returning Zod SafeParseReturnType, index.js barrel).
- Draft REQs matching the four observable behaviors shared across all 11 models, capped at 4.
- Notes section surfaces observed-but-suspicious behavior (string-boolean FIXME, publicationType not in canonical type list, istanbul-ignored coverage).

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../../.github/docs/claude/retrofit.md). Cluster: `entity-typescript-models`. Umbrella: ConductionNL/opencatalogi#664.
