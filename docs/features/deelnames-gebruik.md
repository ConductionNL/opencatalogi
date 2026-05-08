# Deelnames Gebruik

## Overview

Organizations in the Dutch municipal landscape frequently share software applications through cooperation agreements. The deelnames gebruik feature enables organizations to see not only software they directly own, but also shared applications where they participate as a deelnemer (participant).

## How It Works

When viewing GEMMA ArchiMate architecture views, the system performs a two-phase data retrieval:

1. **Owned gebruik** - Standard RBAC-filtered query for the organization's own gebruiksobjecten
2. **Deelnames gebruik** - RBAC-bypassed query for gebruiksobjecten where the organization appears in the `deelnemers` field

## Frontend Controls

The view filter panel provides two independent toggles:

- **Gebruik** - Shows directly owned software applications
- **Deelnames** - Shows shared applications (disabled by default)

Both toggles operate independently, allowing any combination of data layers.

## Deduplication

When the same module appears in both owned and deelnames results (e.g., an organization both owns and participates in a shared instance), the owned version takes precedence and only one node is rendered.

## Source Attribution

Deelnames module nodes display the source organization name in tooltips, making it clear which organization owns each shared application.
