# View Enrichment API

## Overview

The view enrichment API acts as the single entry point for all GEMMA view data, aggregating base ArchiMate view data with organization-specific module mappings, gebruik, and deelnames into a unified response. This replaces direct OpenRegister calls from the frontend.

## Endpoint

The enrichment API endpoint accepts the following parameters:

- `view_id` - The GEMMA view identifier
- `organization_id` - The organization context
- `include_modules` - Include module application mappings (default: false)
- `include_gebruik` - Include owned usage data (default: false)
- `include_deelnames_gebruik` - Include shared usage data (default: false)

## Response Format

The API returns a standard viewNode array with enrichment metadata:

- Base GEMMA view nodes (always included)
- Module overlay nodes (when `include_modules=true`)
- Gebruik overlay nodes (when `include_gebruik=true`)
- Deelnames overlay nodes (when `include_deelnames_gebruik=true`)

## Error Handling

- **404** - View not found
- **500** - Server error during enrichment (partial data may be returned)
