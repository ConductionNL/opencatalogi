// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for opencatalogi's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages that don't fit one of the
// manifest's built-in types/widgets. Keep this file SHORT. Adding entries
// should require explicit justification (see _note in manifest.json).
// Deleting them (when a lib primitive covers the use-case) is the right
// direction.
//
// Resolution order at runtime:
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// Lib gaps documented here (see also src/manifest.json _note fields):
//   - @resolve: sentinel support: static register/schema can't reference
//     IAppConfig values at build time (ADR-024 future feature).
//   - dynamicSource in menu: per-tenant catalog entries need CnAppNav
//     dynamicSource support (not yet in beta.58).
//   - Named-view sidebar: SearchSideBar uses Vue Router named views
//     (<router-view name="sidebar">); CnAppRoot routing layer doesn't
//     support this yet.
//   - type:'dashboard' template-slot pass-through: opencatalogi's dashboard
//     uses #widget-* slot templates; lib needs pass-through for custom widget
//     bodies.

// --- Main feature views (all type:'custom' due to runtime register/schema). ---
import DashboardView from './views/dashboard/Dashboard.vue'
import CatalogiIndexView from './views/catalogi/CatalogiIndex.vue'
import CatalogDetailPageView from './views/catalogi/CatalogDetailPage.vue'
import PublicationIndexView from './views/publications/PublicationIndex.vue'
import PublicationDetailPageView from './views/publications/PublicationDetailPage.vue'
import SearchIndexView from './views/search/SearchIndex.vue'
import OrganizationIndexView from './views/organizations/OrganizationIndex.vue'
import ThemeIndexView from './views/themes/ThemeIndex.vue'
import GlossaryIndexView from './views/glossary/GlossaryIndex.vue'
import PageIndexView from './views/pages/PageIndex.vue'
import MenuIndexView from './views/menus/MenuIndex.vue'
import DirectoryIndexView from './views/directory/DirectoryIndex.vue'

// --- Generic detail wrapper. One component drives every per-entity
//     detail page via manifest `config` props (entityType, entityLabel,
//     icon, apiPath, backRoute, editModal, extraMetadataFields). The
//     four per-entity Vue wrappers (Theme/Glossary/Page/Menu detail)
//     were removed in this pass — the manifest carries the config now.
import EntityDetailView from './views/shared/EntityDetailPage.vue'

export default {
	// --- Dashboard (custom chart/stats widgets, named slot templates). ---
	DashboardView,

	// --- Catalog browsing (register/schema runtime-resolved from IAppConfig). ---
	CatalogiIndexView,
	CatalogDetailPageView,

	// --- Per-catalog publication browsing (register/schema = catalog['@self'] at runtime). ---
	PublicationIndexView,
	PublicationDetailPageView,

	// --- Cross-catalog search with named-view sidebar + faceted filter. ---
	SearchIndexView,

	// --- Settings / admin views (register/schema runtime-resolved from IAppConfig). ---
	OrganizationIndexView,
	ThemeIndexView,
	GlossaryIndexView,
	PageIndexView,
	MenuIndexView,
	DirectoryIndexView,

	// --- Generic entity-detail wrapper (replaces 4 per-entity files). ---
	EntityDetailView,
}
