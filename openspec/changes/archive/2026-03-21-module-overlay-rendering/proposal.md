# Module Overlay Rendering Specification

## Problem
Defines how application/module nodes injected by the enrichment API are rendered on GEMMA ArchiMate views, including visual styling, positioning, performance requirements, and interaction behavior. Module overlay nodes represent organization-specific software applications plotted on top of the standard GEMMA reference architecture, enabling organizations to visualize their application landscape in the context of the national standard.

## Proposed Solution
Implement Module Overlay Rendering Specification following the detailed specification. Key requirements include:
- Requirement: Module nodes MUST render as children of referentiecomponenten
- Requirement: Module nodes MUST be visually distinct from GEMMA elements
- Requirement: Rendering MUST use paper.freeze optimization
- Requirement: Topological sort MUST handle module overlay nodes
- Requirement: setNodeColor MUST handle module overlay nodes

## Scope
This change covers all requirements defined in the module-overlay-rendering specification.

## Success Criteria
- Module node with parent reference renders inside referentiecomponent
- Module appears in multiple referentiecomponenten
- Module node without valid parent reference is skipped
- Referentiecomponent with no modules renders normally
- Module node respects parent bounds
