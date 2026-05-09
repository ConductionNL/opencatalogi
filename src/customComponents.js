// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for OpenCatalogi's manifest-driven app shell.
//
// Every entry here is the "escape hatch" — pages that don't yet fit one
// of the manifest's built-in types (`index`, `detail`, `dashboard`,
// `logs`, `settings`, `chat`, `files`). For OpenCatalogi v1 the bar to
// migrate is high: most pages drive `navigationStore`-mediated modals/
// dialogs, route-aware fetches, or bespoke widget content that the lib
// doesn't yet express. Each retention is documented in
// `openspec/changes/opencatalogi-manifest-v1/design.md`'s
// "Per-page mapping" table.
//
// Resolution order at runtime:
//   1. Built-in page types (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See:
//   - openspec/changes/opencatalogi-manifest-v1/design.md
//   - hydra/openspec/architecture/adr-024-app-manifest.md
//   - @conduction/nextcloud-vue → docs/migrating-to-manifest.md

import DashboardView from './views/dashboard/Dashboard.vue'
import CatalogsView from './views/catalogi/CatalogiIndex.vue'
import CatalogDetailView from './views/catalogi/CatalogDetailPage.vue'
import PublicationsView from './views/publications/PublicationIndex.vue'
import PublicationDetailView from './views/publications/PublicationDetailPage.vue'
import SearchView from './views/search/SearchIndex.vue'
import OrganizationsView from './views/organizations/OrganizationIndex.vue'
import ThemesView from './views/themes/ThemeIndex.vue'
import ThemeDetailView from './views/themes/ThemeDetailPage.vue'
import GlossaryView from './views/glossary/GlossaryIndex.vue'
import GlossaryDetailView from './views/glossary/GlossaryDetailPage.vue'
import PagesView from './views/pages/PageIndex.vue'
import PageDetailView from './views/pages/PageDetailPage.vue'
import MenusView from './views/menus/MenuIndex.vue'
import MenuDetailView from './views/menus/MenuDetailPage.vue'

export default {
	// Heavy bespoke widgets — CnChartWidget donut + area, CnStatsBlock
	// per-tile slot overrides, layout-change persistence.
	DashboardView,

	// Drive navigationStore.setModal/setDialog for create/edit/copy/delete
	// flows that the manifest's row actions don't yet replicate.
	CatalogsView,
	CatalogDetailView,
	OrganizationsView,
	ThemesView,
	ThemeDetailView,
	GlossaryView,
	GlossaryDetailView,
	PagesView,
	PageDetailView,
	MenusView,
	MenuDetailView,

	// :catalogSlug param drives the catalog-aware /api/{catalogSlug}
	// endpoint; the renderer's `index` type doesn't yet route through
	// per-tenant endpoints.
	PublicationsView,
	PublicationDetailView,

	// 467-LOC federated search — useSearchStore, save-search dialogs,
	// in-page sidebar (was a router-named view).
	SearchView,
}
