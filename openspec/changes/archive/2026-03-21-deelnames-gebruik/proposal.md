# Deelnames Gebruik Specification

## Problem
Defines how usage objects (gebruiksobjecten) with participant organizations (deelnemers) are queried, enriched, and displayed alongside regular organization-owned modules on GEMMA views. This enables organizations to see not only the software they directly use, but also shared applications where they participate as a deelnemer (participant) through inter-organizational cooperation agreements.

## Proposed Solution
Implement Deelnames Gebruik Specification following the detailed specification. Key requirements include:
- Requirement: ViewService MUST retrieve deelnames gebruik separately from regular gebruik
- Requirement: Deelnames gebruik MUST be queried with RBAC disabled
- Requirement: Gebruiksobjecten MUST support the deelnemers field
- Requirement: Deelnames module nodes MUST carry source organization metadata
- Requirement: Deelnames gebruik MUST be filterable in the frontend

## Scope
This change covers all requirements defined in the deelnames-gebruik specification.

## Success Criteria
- Organization has both owned and shared gebruik
- Organization has only deelnames gebruik
- Deelnames flag is not set
- Both flags disabled returns base view only
- Deelnames without gebruik flag still returns deelnames
