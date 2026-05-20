// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// Post-beta.63 migration: most index/detail/search pages flipped to typed
// manifest entries; the registry now mostly registers detail-page widgets
// (theme-preview / tree-view / relationship-graph / file-manager) plus
// the two remaining stay-custom page components and the lib-provided
// Directory page component. The pre-migration per-feature page-kind
// entries (CatalogiIndexView, ThemeIndexView, …) are gone — those views
// are now CnIndexPage / CnDetailPage / CnSearchPage from the lib.
//
// Lib gaps still documented in src/manifest.json _note fields:
//   - DashboardView: type:'dashboard' #widget-* slot template pass-through
//   - CatalogDetailPageView: header-component slot resolution from manifest
//
// References:
//   - hydra ADR-036
//   - nextcloud-app-template scaffold-v2 (#44) — canonical layout
//   - procest #512 / mydash #206 — first reference migrations

import DashboardView from './views/dashboard/Dashboard.vue'
import CatalogDetailPageView from './views/catalogi/CatalogDetailPage.vue'
import {
	CnFederationStatus,
	CnFileManager,
	CnRelationshipGraph,
	CnThemePreview,
	CnTreeView,
} from '@conduction/nextcloud-vue'

// Widget metadata: CnAppRoot warns if these fields are missing on a
// kind:'widget' entry. Sizes are advisory hints to the page renderer;
// the manifest's per-instance gridWidth/gridHeight always wins.
const FULL_ROW = { defaultSize: { w: 6, h: 4 }, minSize: { w: 3, h: 2 }, maxSize: { w: 12, h: 12 } }
const SIDEBAR_ALLOWED = ['body', 'sidebar']

export default {
	// --- Stay-custom page components (see _note in manifest.json). ---
	DashboardView: { kind: 'page', component: DashboardView },
	CatalogDetailPageView: { kind: 'page', component: CatalogDetailPageView },

	// --- Lib-provided page component for Directory (federation status). ---
	CnFederationStatus: { kind: 'page', component: CnFederationStatus },

	// --- Detail-page widgets (referenced by widgetKey in manifest pages). ---
	'theme-preview': {
		kind: 'widget',
		component: CnThemePreview,
		...FULL_ROW,
		allowedSlots: SIDEBAR_ALLOWED,
		propsSchema: { type: 'object' },
	},
	'tree-view': {
		kind: 'widget',
		component: CnTreeView,
		...FULL_ROW,
		allowedSlots: SIDEBAR_ALLOWED,
		propsSchema: { type: 'object' },
	},
	'relationship-graph': {
		kind: 'widget',
		component: CnRelationshipGraph,
		...FULL_ROW,
		allowedSlots: SIDEBAR_ALLOWED,
		propsSchema: { type: 'object' },
	},
	'file-manager': {
		kind: 'widget',
		component: CnFileManager,
		...FULL_ROW,
		allowedSlots: SIDEBAR_ALLOWED,
		propsSchema: { type: 'object' },
	},
}
