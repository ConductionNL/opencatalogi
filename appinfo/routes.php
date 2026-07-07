<?php

return [
	'routes' => [
		/**
		 * Here we have the private endpoints, the part of the API that is used by the backend and not publicly accessible
		 */
		// Dashboard SPA page — served by OpenRegister's AppHost GenericDashboardController
		// (aliased at OCA\OpenCatalogi\AppHost\Controller\GenericDashboardController in
		// Application::register, mirroring the Health/Metrics adoption precedent). The
		// controller portion must be the fully-qualified leaf class name minus the
		// `Controller` suffix: RouteParser::buildControllerName() only appends `Controller`,
		// and App::main() resolves the result through the leaf DI container, which can only
		// reach names under the `OCA\OpenCatalogi\` namespace. A short `AppHost\Controller\…`
		// name resolves to a string outside that namespace and fails with the misleading
		// "App controller is not enabled". URL + auth posture unchanged.
		['name' => 'OCA\OpenCatalogi\AppHost\Controller\GenericDashboard#page', 'url' => '/', 'verb' => 'GET'],

		// Catalogi
		['name' => 'catalogi#index', 'url' => '/api/catalogi', 'verb' => 'GET'], // Public endpoint for getting all catalogs
		['name' => 'catalogi#show', 'url' => '/api/catalogi/{id}', 'verb' => 'GET'],
		// Catalogi sitemap
		['name' => 'sitemap#index', 'url' => '/api/{catalogSlug}/sitemaps/{categoryCode}', 'verb' => 'GET'],
		['name' => 'sitemap#sitemap', 'url' => '/api/{catalogSlug}/sitemaps/{categoryCode}/publications', 'verb' => 'GET'],
		// DCAT-AP-NL feed validation (admin-only — auditable via AuthorizedAdminSetting)
		['name' => 'dcat#validate', 'url' => '/api/catalogs/{catalogSlug}/dcat/validate', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		// Robots
		['name' => 'robots#index', 'url' => '/api/robots.txt', 'verb' => 'GET'],
		// Global Configuration
		['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
		['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'GET'],
		// Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog) —
		// served by OpenRegister's AppHost GenericPreferencesController (aliased in Application::register).
		['name' => 'OCA\OpenCatalogi\AppHost\Controller\GenericPreferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
		['name' => 'OCA\OpenCatalogi\AppHost\Controller\GenericPreferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],
		['name' => 'settings#getPublishingOptions', 'url' => '/api/settings/publishing', 'verb' => 'GET'],
		['name' => 'settings#updatePublishingOptions', 'url' => '/api/settings/publishing', 'verb' => 'POST'],
		['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],
		['name' => 'settings#manualImport', 'url' => '/api/settings/import', 'verb' => 'POST'],
		// Retention lifecycle (publication-retention-lifecycle)
		['name' => 'retention#queueSummary', 'url' => '/api/retention/queue', 'verb' => 'GET'],
		['name' => 'retention#getDefaults', 'url' => '/api/retention/defaults', 'verb' => 'GET'],
		['name' => 'retention#setDefaults', 'url' => '/api/retention/defaults', 'verb' => 'POST'],
		['name' => 'retention#decide', 'url' => '/api/retention/publications/{id}/decision', 'verb' => 'POST'],
		['name' => 'retention#exportReport', 'url' => '/api/retention/report', 'verb' => 'GET'],
		// WOO transparency (woo-transparency)
		['name' => 'woo#weigeringsgronden', 'url' => '/api/woo/weigeringsgronden', 'verb' => 'GET'],
		['name' => 'woo#createBatch', 'url' => '/api/woo/batches', 'verb' => 'POST'],
		['name' => 'woo#getBatch', 'url' => '/api/woo/batches/{batchId}', 'verb' => 'GET'],
		['name' => 'woo#updateAssessment', 'url' => '/api/woo/batches/{batchId}/documents/{docId}', 'verb' => 'PUT'],
		['name' => 'woo#markReadyForReview', 'url' => '/api/woo/batches/{batchId}/ready-for-review', 'verb' => 'POST'],
		['name' => 'woo#inventarislijst', 'url' => '/api/woo/batches/{batchId}/inventarislijst', 'verb' => 'POST'],
		['name' => 'woo#publishBatch', 'url' => '/api/woo/batches/{batchId}/publish', 'verb' => 'POST'],
		/**
		 * CORS preflight OPTIONS routes for public endpoints
		 */
		
		// Publications CORS (wildcard catalog-based endpoints)
		['name' => 'publications#preflightedCors', 'url' => '/api/{catalogSlug}', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#preflightedCors', 'url' => '/api/{catalogSlug}/{id}', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#preflightedCors', 'url' => '/api/{catalogSlug}/{id}/uses', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#preflightedCors', 'url' => '/api/{catalogSlug}/{id}/used', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#preflightedCors', 'url' => '/api/{catalogSlug}/{id}/attachments', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#preflightedCors', 'url' => '/api/{catalogSlug}/{id}/download', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		// DCAT-AP-NL CORS (public harvest endpoints)
		['name' => 'dcat#preflightedCors', 'url' => '/api/dcat', 'verb' => 'OPTIONS'],
		['name' => 'dcat#preflightedCors', 'url' => '/api/catalogs/{catalogSlug}/dcat', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		// Catalogi CORS
		['name' => 'catalogi#preflightedCors', 'url' => '/api/catalogi', 'verb' => 'OPTIONS'],
		['name' => 'catalogi#preflightedCors', 'url' => '/api/catalogi/{id}', 'verb' => 'OPTIONS'],
		// Glossary CORS
		['name' => 'glossary#preflightedCors', 'url' => '/api/glossary', 'verb' => 'OPTIONS'],
		['name' => 'glossary#preflightedCors', 'url' => '/api/glossary/{id}', 'verb' => 'OPTIONS'],
		// Themes CORS
		['name' => 'themes#preflightedCors', 'url' => '/api/themes', 'verb' => 'OPTIONS'],
		['name' => 'themes#preflightedCors', 'url' => '/api/themes/{id}', 'verb' => 'OPTIONS'],
		// Menus CORS
		['name' => 'menus#preflightedCors', 'url' => '/api/menus', 'verb' => 'OPTIONS'],
		['name' => 'menus#preflightedCors', 'url' => '/api/menus/{id}', 'verb' => 'OPTIONS'],
		// Pages CORS
		['name' => 'pages#preflightedCors', 'url' => '/api/pages', 'verb' => 'OPTIONS'],
		['name' => 'pages#preflightedCors', 'url' => '/api/pages/{slug}', 'verb' => 'OPTIONS', 'requirements' => ['slug' => '.+']],
		// Directory CORS
		['name' => 'directory#preflightedCors', 'url' => '/api/directory', 'verb' => 'OPTIONS'],
		// Listings CORS
		['name' => 'listings#preflightedCors', 'url' => '/api/listings', 'verb' => 'OPTIONS'],
		['name' => 'listings#preflightedCors', 'url' => '/api/listings/{id}', 'verb' => 'OPTIONS'],
		['name' => 'listings#preflightedCors', 'url' => '/api/listings/sync', 'verb' => 'OPTIONS'],
		['name' => 'listings#preflightedCors', 'url' => '/api/listings/add', 'verb' => 'OPTIONS'],
		/**
		 * And here we have the public endpoints, the part of the API that is used by the frontend and publicly accessible
		 * 
		 * IMPORTANT: Routes are matched in order from top to bottom.
		 * Specific routes MUST come BEFORE wildcard routes to avoid incorrect matching.
		 */
		// DCAT-AP-NL harvest endpoints (specific routes - MUST be before wildcard catalog routes)
		['name' => 'dcat#instance', 'url' => '/api/dcat', 'verb' => 'GET'],
		['name' => 'dcat#catalog', 'url' => '/api/catalogs/{catalogSlug}/dcat', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		// schema.org DataCatalog endpoint (specific route - must be before wildcard catalog routes)
		['name' => 'schemaOrg#catalog', 'url' => '/api/catalogs/{catalogSlug}/schema', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'schemaOrg#preflightedCors', 'url' => '/api/catalogs/{catalogSlug}/schema', 'verb' => 'OPTIONS', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		// Glossary (specific route - must be before wildcard catalog routes)
		['name' => 'glossary#index', 'url' => '/api/glossary', 'verb' => 'GET'],
		['name' => 'glossary#show', 'url' => '/api/glossary/{id}', 'verb' => 'GET'],
		// Themes (specific route - must be before wildcard catalog routes)
		['name' => 'themes#index', 'url' => '/api/themes', 'verb' => 'GET'],
		['name' => 'themes#show', 'url' => '/api/themes/{id}', 'verb' => 'GET'],
		// Menus (specific route - must be before wildcard catalog routes)
		['name' => 'menus#index', 'url' => '/api/menus', 'verb' => 'GET'],
		['name' => 'menus#show', 'url' => '/api/menus/{id}', 'verb' => 'GET'],
		// Pages (specific route - must be before wildcard catalog routes)
		['name' => 'pages#index', 'url' => '/api/pages', 'verb' => 'GET'],
		['name' => 'pages#show', 'url' => '/api/pages/{slug}', 'verb' => 'GET', 'requirements' => ['slug' => '.+']],
		// Directory (specific route - must be before wildcard catalog routes)
		['name' => 'directory#index', 'url' => '/api/directory', 'verb' => 'GET'],
		['name' => 'directory#update', 'url' => '/api/directory', 'verb' => 'POST'],
		// Listings (specific route - must be before wildcard catalog routes)
		['name' => 'listings#index', 'url' => '/api/listings', 'verb' => 'GET'],
		['name' => 'listings#create', 'url' => '/api/listings', 'verb' => 'POST'],
		['name' => 'listings#synchronise', 'url' => '/api/listings/sync', 'verb' => 'POST'],
		['name' => 'listings#add', 'url' => '/api/listings/add', 'verb' => 'POST'],
		['name' => 'listings#show', 'url' => '/api/listings/{id}', 'verb' => 'GET'],
		['name' => 'listings#update', 'url' => '/api/listings/{id}', 'verb' => 'PUT'],
		['name' => 'listings#destroy', 'url' => '/api/listings/{id}', 'verb' => 'DELETE'],
		// Prometheus metrics endpoint — served by OpenRegister's AppHost engine
		// (ADR-040 / ADR-006). The canonical /api/metrics URL is aliased at the
		// GenericMetricsController, which reads the `observability.metrics` block
		// of src/manifest.json (and this app's IMetricsProvider escape hatch).
		// URL + Prometheus output contract are unchanged from the deleted
		// MetricsController; the engine owns the admin-only auth posture.
		// (Specific route - must be before wildcard catalog routes.)
		['name' => 'OCA\OpenCatalogi\AppHost\Controller\GenericMetrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
		// Usage analytics (authenticated; specific routes - MUST be before wildcard catalog routes).
		['name' => 'stats#publication', 'url' => '/api/publications/{id}/stats', 'verb' => 'GET'],
		['name' => 'stats#catalog', 'url' => '/api/catalogs/{slug}/stats', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9-]+']],
		['name' => 'stats#export', 'url' => '/api/catalogs/{slug}/stats/export', 'verb' => 'GET', 'requirements' => ['slug' => '[a-z0-9-]+']],
		// Health check endpoint — served by the AppHost engine from the
		// `observability.health` block (ADR-040 / ADR-006). The engine adds
		// #[PublicPage] (anonymous health — an intentional improvement over the
		// bespoke login-gated controller) and owns the {status, app, version,
		// checks} contract. URL unchanged. (Specific route - must be before
		// wildcard catalog routes.)
		['name' => 'OCA\OpenCatalogi\AppHost\Controller\GenericHealth#index', 'url' => '/api/health', 'verb' => 'GET'],
		// Search (specific route - must be before wildcard catalog routes)
		['name' => 'search#index', 'url' => '/api/search', 'verb' => 'GET'],
		['name' => 'search#show', 'url' => '/api/search/{id}', 'verb' => 'GET'],
		['name' => 'search#attachments', 'url' => '/api/search/{id}/attachments', 'verb' => 'GET'],
		['name' => 'search#download', 'url' => '/api/search/{id}/download', 'verb' => 'GET'],
		['name' => 'search#uses', 'url' => '/api/search/{id}/uses', 'verb' => 'GET'],
		['name' => 'search#used', 'url' => '/api/search/{id}/used', 'verb' => 'GET'],
		// Federation (specific route - must be before wildcard catalog routes)
		['name' => 'federation#publications', 'url' => '/api/federation/publications', 'verb' => 'GET'],
		['name' => 'federation#publication', 'url' => '/api/federation/publications/{id}', 'verb' => 'GET'],
		['name' => 'federation#publicationUses', 'url' => '/api/federation/publications/{id}/uses', 'verb' => 'GET'],
		['name' => 'federation#publicationUsed', 'url' => '/api/federation/publications/{id}/used', 'verb' => 'GET'],
		['name' => 'federation#publicationAttachments', 'url' => '/api/federation/publications/{id}/attachments', 'verb' => 'GET'],
		['name' => 'federation#publicationDownload', 'url' => '/api/federation/publications/{id}/download', 'verb' => 'GET'],
		// First-time-setup contract (ADR-042). Specific routes — MUST be before the
		// wildcard catalog routes below, otherwise `/api/setup/status` resolves to
		// publications#show with catalogSlug=setup ("catalog 'setup' does not exist").
		['name' => 'setup#status', 'url' => '/api/setup/status', 'verb' => 'GET'],
		['name' => 'setup#config', 'url' => '/api/setup/config', 'verb' => 'POST'],
		['name' => 'setup#action', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[a-z-]+']],
		// Publications (wildcard catalog-based endpoints - MUST BE ABSOLUTE LAST to avoid catching any specific routes)
		['name' => 'publications#index', 'url' => '/api/{catalogSlug}', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#show', 'url' => '/api/{catalogSlug}/{id}', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#uses', 'url' => '/api/{catalogSlug}/{id}/uses', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#used', 'url' => '/api/{catalogSlug}/{id}/used', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#attachments', 'url' => '/api/{catalogSlug}/{id}/attachments', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'publications#download', 'url' => '/api/{catalogSlug}/{id}/download', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],

		// UI page routes for SPA deep links
		['name' => 'ui#dashboard', 'url' => '/', 'verb' => 'GET'],
		['name' => 'ui#catalogi', 'url' => '/catalogi', 'verb' => 'GET'],
		['name' => 'ui#publicationsIndex', 'url' => '/publications/{catalogSlug}', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+']],
		['name' => 'ui#publicationsPage', 'url' => '/publications/{catalogSlug}/{id}', 'verb' => 'GET', 'requirements' => ['catalogSlug' => '[a-z0-9-]+', 'id' => '[a-z0-9-]+']],
		['name' => 'ui#search', 'url' => '/search', 'verb' => 'GET'],
		['name' => 'ui#organizations', 'url' => '/organizations', 'verb' => 'GET'],
		['name' => 'ui#themes', 'url' => '/themes', 'verb' => 'GET'],
		['name' => 'ui#glossary', 'url' => '/glossary', 'verb' => 'GET'],
		['name' => 'ui#pages', 'url' => '/pages', 'verb' => 'GET'],
		['name' => 'ui#menus', 'url' => '/menus', 'verb' => 'GET'],
		['name' => 'ui#directory', 'url' => '/directory', 'verb' => 'GET'],
		// SPA catch-all — serves the Vue app for any frontend route (history mode routing).
		// GenericDashboard#catchAll delegates to page() on the AppHost GenericDashboardController
		// (aliased in Application::register); a distinct name keeps it from shadowing the / index route.
		['name' => 'OCA\OpenCatalogi\AppHost\Controller\GenericDashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
	]
];
