# OpenCatalogi

## Overview
OpenCatalogi is a Nextcloud app for publishing government information in compliance with WOO (Wet Open Overheid). It provides a publication system with catalogi, publications, listings, and themes.

## Repository
- **GitHub**: https://github.com/ConductionNL/opencatalogi
- **Organization**: ConductionNL
- **Container mount**: `/var/www/html/custom_apps/opencatalogi`

## Architecture

### Key Components
- **Catalogi** — Collections/categories of publications
- **Publications** — Individual published documents/records
- **Listings** — Publication discovery and search
- **Themes** — Thematic categorization
- **Attachments** — Files linked to publications

### Important Patterns
- Uses OpenRegister's `ObjectService` for data persistence (NOT direct database access)
- Config keys in `IAppConfig`: `listing_schema`, `listing_register`, `listing_source`
- Public endpoints use `@CORS`, `@NoCSRFRequired`, `@PublicPage` annotations
- Route ordering: specific routes MUST come before wildcard `{catalogSlug}` routes

### Directory Structure
```
lib/
  Controller/       # API and page controllers
  Service/          # Business logic (ListingService, PublicationService, etc.)
  Db/               # Entities and Mappers
  Migration/        # Database migrations
appinfo/
  info.xml          # App metadata
  routes.php        # Route definitions
```

## Dependencies
- **Depends on**: OpenRegister (ObjectService), Nextcloud core, PostgreSQL
- **Depended on by**: tilburg-woo-ui (frontend), softwarecatalog

## Frontends
- **tilburg-woo-ui** — React frontend for WOO publications (port 3000)
- API consumed via `/index.php/apps/opencatalogi/api/...`

## API
- Base URL: `/index.php/apps/opencatalogi/api/`
- Public endpoints: publications, listings, search
- Auth: Nextcloud session or Basic auth (admin endpoints), public (read-only endpoints)
- Format: JSON

## Testing
- Backend: via Nextcloud container
- Frontend integration: verify with tilburg-woo-ui running on port 3000
