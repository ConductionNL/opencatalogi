# Tasks: module-overlay-rendering

## 1. Module Node Rendering

- [x] 1.1 Create module overlay nodes as children of referentiecomponenten
- [x] 1.2 Apply distinct visual styling (color, opacity) to module nodes
- [x] 1.3 Position module nodes within parent referentiecomponent bounds
- [x] 1.4 Skip module nodes without valid parent reference

## 2. Performance

- [x] 2.1 Use paper.freeze/unfreeze for batch module rendering
- [x] 2.2 Handle topological sort with module overlay nodes

## 3. Interaction

- [x] 3.1 setNodeColor handles module overlay nodes
- [x] 3.2 Module nodes display name/tooltip on hover

## 4. Unit Tests (ADR-009)

- [x] 4.1 Test module node renders inside referentiecomponent bounds
- [x] 4.2 Test node without valid parent is skipped

## 5. Documentation (ADR-010)

- [x] 5.1 Feature documentation at docs/features/module-overlay-rendering.md

## 6. Internationalization (ADR-005)

- [x] 6.1 Module tooltip labels translatable — N/A (data-driven labels)
