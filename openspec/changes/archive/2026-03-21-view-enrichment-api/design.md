# Design: view-enrichment-api

## Context

Defines how the frontend obtains enriched view data (base GEMMA view + organization-specific modules and usage data) through the softwarecatalog enrichment API, replacing direct OpenRegister calls. Acts as the single entry point for all GEMMA view data.

## Goals / Non-Goals

**Goals:**
- Frontend calls enrichment API for views instead of direct OpenRegister calls
- Frontend filter toggles map to backend enrichment parameters
- Enrichment API returns standard viewNode format
- Enrichment API supports organization context
- Endpoint constants updated

**Non-Goals:**
- Client-side view data caching
- WebSocket real-time updates

## Decisions

1. Single enrichment endpoint aggregates base ArchiMate + modules + gebruik + deelnames
2. Parameters: `include_modules`, `include_gebruik`, `include_deelnames_gebruik`
3. Response format: standard viewNode array with enrichment metadata

## File Changes

- Frontend API service — switch to enrichment API endpoints
- Frontend filter panel — map toggles to API parameters
- Backend enrichment controller — aggregation endpoint
