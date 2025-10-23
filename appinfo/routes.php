<?php

return [
	'routes' => [
		/**
		 * Here we have the private endpoints, the part of the API that is used by the backend and not publicly accessible
		 */
		// Dashboard
		['name' => 'dashboard#index', 'url' => '/index', 'verb' => 'GET'],
        // this may seem like a duplicate of the UI routes at the bottom, but this is needed
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],

		// Catalogi
		['name' => 'catalogi#index', 'url' => '/api/catalogi', 'verb' => 'GET'], // Public endpoint for getting all catalogs
		['name' => 'catalogi#show', 'url' => '/api/catalogi/{id}', 'verb' => 'GET'],
		// Global Configuration
		['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
		['name' => 'settings#load', 'url' => '/api/settings/load', 'verb' => 'GET'],
		['name' => 'settings#getPublishingOptions', 'url' => '/api/settings/publishing', 'verb' => 'GET'],
		['name' => 'settings#updatePublishingOptions', 'url' => '/api/settings/publishing', 'verb' => 'POST'],
		['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],
		['name' => 'settings#manualImport', 'url' => '/api/settings/import', 'verb' => 'POST'],
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
		/**
		 * And here we have the public endpoints, the part of the API that is used by the frontend and publicly accessible
		 * 
		 * IMPORTANT: Routes are matched in order from top to bottom.
		 * Specific routes MUST come BEFORE wildcard routes to avoid incorrect matching.
		 */
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
		// Search (specific route - must be before wildcard catalog routes)
		['name' => 'search#index', 'url' => '/api/search', 'verb' => 'GET'],
		// Federation (specific route - must be before wildcard catalog routes)
		['name' => 'federation#publications', 'url' => '/api/federation/publications', 'verb' => 'GET'],
		['name' => 'federation#publication', 'url' => '/api/federation/publications/{id}', 'verb' => 'GET'],
		['name' => 'federation#publicationUses', 'url' => '/api/federation/publications/{id}/uses', 'verb' => 'GET'],
		['name' => 'federation#publicationUsed', 'url' => '/api/federation/publications/{id}/used', 'verb' => 'GET'],
		['name' => 'federation#publicationAttachments', 'url' => '/api/federation/publications/{id}/attachments', 'verb' => 'GET'],
		['name' => 'federation#publicationDownload', 'url' => '/api/federation/publications/{id}/download', 'verb' => 'GET'],
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
	]
];
