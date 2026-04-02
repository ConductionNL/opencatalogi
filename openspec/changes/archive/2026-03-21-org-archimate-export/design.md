# Design: org-archimate-export

## Context

Defines how the softwarecatalog app exports an organization-enriched ArchiMate (AMEFF) XML file that includes the base GEMMA model plus the organization's applications plotted on referentiecomponenten. Supports toggling data layers via query parameters.

## Goals / Non-Goals

**Goals:**
- Export valid ArchiMate XML with organization applications
- Application elements as ApplicationComponent type with Bron property
- SpecializationRelationship links applications to referentiecomponenten
- Views copied with applications plotted inside referentiecomponenten
- View copies use Titel view SWC property for naming

**Non-Goals:**
- Import of ArchiMate XML back into the system
- Real-time synchronization with Archi tool

## Decisions

1. Export produces standard AMEFF XML importable by Archi and other tools
2. Applications organized into typed folders (Applicaties, Relaties, Views)
3. Query parameters control inclusion of modules, gebruik, deelnames layers

## File Changes

- Backend export controller/service — AMEFF XML generation
- Frontend export button/UI — trigger download with parameters
