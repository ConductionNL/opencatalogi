# Design: module-overlay-rendering

## Context

Defines how application/module nodes injected by the enrichment API are rendered on GEMMA ArchiMate views using JointJS. Module overlay nodes represent organization-specific software applications plotted on top of the standard GEMMA reference architecture.

## Goals / Non-Goals

**Goals:**
- Module nodes render as children of referentiecomponenten
- Visual distinction from GEMMA elements (different color/opacity)
- paper.freeze optimization for batch rendering
- Topological sort handles module nodes
- setNodeColor handles module overlay nodes

**Non-Goals:**
- Interactive drag/drop of module nodes
- Editing module-referentiecomponent mappings from the view

## Decisions

1. Module nodes use a distinct background color and reduced opacity to differentiate from GEMMA elements
2. Rendering uses paper.freeze()/unfreeze() for performance with large node counts
3. Module nodes positioned within parent referentiecomponent bounds

## File Changes

- Frontend JointJS rendering pipeline — module node creation and positioning
- Frontend color/style functions — module node styling
- Frontend topological sort — handle module overlay nodes in layout
