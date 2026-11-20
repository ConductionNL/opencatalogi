# Content management

## REMOVED Requirements

### Requirement: List all pages with pagination via public API (CMS-001)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Retrieve a single page by slug (CMS-002)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Pages support block-based content structure (contents array with type, data, groups) (CMS-003)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Pages support group-based access control (groups, hideAfterLogin, hideBeforeLogin) (CMS-004)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Page configuration stored in IAppConfig as `page_schema` and `page_register` (CMS-005)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: CORS headers included on all page endpoints (CMS-006)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: List all menus with pagination via public API (CMS-010)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Retrieve a single menu by ID (CMS-011)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Menus support hierarchical items with sub-items (CMS-012)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Menu items support group-based visibility (groups, hideAfterLogin, hideBeforeLogin) (CMS-013)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Menu configuration stored in IAppConfig as `menu_schema` and `menu_register` (CMS-014)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Default fallback: menu schema ID 7, register ID 1 when not configured (CMS-015)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: CORS headers included on all menu endpoints (CMS-016)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Page management UI with embedded content blocks (CMS-036)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.

### Requirement: Menu management UI with embedded menu items (CMS-037)

**Reason**: Pages and menus moved to Portaliq (cms-moves-to-portaliq, decision 138). OpenCatalogi no longer declares the `page` and `menu` schemas, serves no page or menu API and no page or menu screen.

**Migration**: Run `occ opencatalogi:cms:migrate-to-portaliq --portal=<portal> --apply` to move pages and menus into a Portaliq portal. Readers use Portaliq's `/api/content/pages` and `/api/content/menus`.
