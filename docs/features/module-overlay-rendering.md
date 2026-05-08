# Module Overlay Rendering

## Overview

Module overlay nodes represent organization-specific software applications plotted on top of the standard GEMMA reference architecture views. This enables organizations to visualize their application landscape in the context of the national standard.

## Rendering

Module nodes are rendered as children of referentiecomponenten (reference components) in the GEMMA ArchiMate view. They are visually distinct from standard GEMMA elements through:

- Different background color and reduced opacity
- Positioned within parent referentiecomponent bounds
- Hover tooltips showing module name and details

## Performance

The rendering pipeline uses JointJS `paper.freeze()`/`paper.unfreeze()` optimization for batch rendering of module nodes. The topological sort algorithm handles module overlay nodes alongside standard GEMMA elements.

## Edge Cases

- Modules without a valid parent referentiecomponent reference are skipped
- Modules appearing in multiple referentiecomponenten are rendered in each
- Referentiecomponenten without modules render normally
