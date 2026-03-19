# Module Overlay Rendering Specification

## Purpose
Defines how application/module nodes injected by the enrichment API are rendered on GEMMA ArchiMate views, including visual styling, positioning, and performance requirements.

## ADDED Requirements

### Requirement: Module nodes MUST render as children of referentiecomponenten
Module overlay nodes returned by the enrichment API MUST be rendered inside their parent referentiecomponent using the existing JointJS parent-child hierarchy.

#### Scenario: Module node with parent reference renders inside referentiecomponent
- GIVEN a viewNode with `_isModuleExpansion: true` and `parent` set to a referentiecomponent's `viewNodeId`
- WHEN the rendering pipeline processes all viewNodes
- THEN the module node MUST be rendered as a child of the referentiecomponent in the JointJS graph
- AND the module node MUST appear visually nested within or adjacent to the referentiecomponent

#### Scenario: Module appears in multiple referentiecomponenten
- GIVEN a module that is linked to 3 referentiecomponenten on the current view
- WHEN the enrichment API returns viewNodes
- THEN there MUST be 3 separate module viewNodes, one per referentiecomponent
- AND each MUST have a different `viewNodeId` but the same `modelNodeId`
- AND each MUST have `parent` pointing to its respective referentiecomponent

### Requirement: Module nodes MUST be visually distinct from GEMMA elements
Module overlay nodes MUST be styled differently from standard GEMMA referentiecomponenten so users can distinguish organization-specific applications from the architecture standard.

#### Scenario: Module node receives distinct fill color
- GIVEN a viewNode with `_isModuleExpansion: true`
- WHEN `setNodeColor` processes this node
- THEN the node's fill color MUST differ from standard referentiecomponent colors
- AND the node's border color MUST differ from standard referentiecomponent borders

#### Scenario: Module node from deelnames has different styling
- GIVEN a viewNode with `_isModuleExpansion: true` and `_type: "deelnames"`
- WHEN `setNodeColor` processes this node
- THEN the node MUST be styled differently from regular module nodes (e.g., different opacity or color shade)
- AND the user MUST be able to distinguish owned modules from shared (deelnames) modules

### Requirement: Rendering MUST use paper.freeze optimization
All view rendering that includes module overlay nodes MUST use the JointJS `paper.freeze()`/`paper.unfreeze()` pattern to maintain performance.

#### Scenario: View with 388 base nodes and 200 module overlay nodes renders
- GIVEN a view with 388 GEMMA nodes and 200 additional module overlay nodes
- WHEN the rendering pipeline executes
- THEN `paper.freeze()` MUST be called before `ViewRenderer.renderToGraph()`
- AND `paper.unfreeze()` MUST be called after `renderToGraph()` completes
- AND total render time MUST be under 3 seconds

#### Scenario: View with no overlay nodes renders unchanged
- GIVEN a view with only base GEMMA nodes (no enrichment)
- WHEN the rendering pipeline executes
- THEN the render behavior and performance MUST be identical to the current implementation

### Requirement: Topological sort MUST handle module overlay nodes
The existing topological sort that orders parent nodes before children MUST correctly process module overlay nodes that reference referentiecomponenten as parents.

#### Scenario: Module overlay nodes sorted after their parent referentiecomponenten
- GIVEN a viewNodes array containing both GEMMA nodes and module overlay nodes
- WHEN the topological sort runs
- THEN every module overlay node MUST appear after its parent referentiecomponent in the sorted array
- AND the sort MUST not produce errors for overlay nodes

### Requirement: setNodeColor MUST handle module overlay nodes
The `setNodeColor` function MUST apply appropriate styling to module overlay nodes based on their metadata markers.

#### Scenario: setNodeColor processes a module node
- GIVEN a rendered SVG element for a viewNode with `_isModuleExpansion: true`
- WHEN `setNodeColor` is called for this node
- THEN it MUST apply the module-specific fill color from the node data
- AND it MUST apply the module-specific border color from the node data
- AND text elements MUST remain readable (sufficient contrast)

## MODIFIED Requirements

_None — this is a new capability._

## REMOVED Requirements

_None._
