// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Custom-component registry for opencatalogi's manifest-driven app shell.
//
// Mirrors the openconnector pattern (post chain-C cutover): a single flat
// `{ComponentName: Component}` map, passed to CnAppRoot as `customComponents`.
// CnAppRoot resolves both `type: "custom"` page references and `widgetKey`
// widget references through this same map.
//
// What's in here:
//   1. DashboardView — custom analytics dashboard (charts + recent activity
//      + per-catalog stats). Could be type:"dashboard" with widget
//      composition once chart widgets land.
//   2. CatalogDetailPageView — bespoke header (catalog stats + slug + edit
//      actions) + sidebar tabs. Lib gap: header-component slot resolution
//      from manifest, granular sidebar-tab declaration.
//   3. CnFederationStatus — federation discovery + per-node availability
//      page for /directory. Lib-provided.
//   4. Detail-page widgets (theme-preview, tree-view, relationship-graph,
//      file-manager) — referenced by widgetKey in manifest pages.
//
// Resolution order at runtime (handled by CnAppRoot/CnPageRenderer):
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (object-table, form-renderer, …)
//   3. This map                     ← consumer-injected components
//
// References:
//   - hydra ADR-036 (5-kind registry; collapsed back to bare shape here
//     because the lookup path reads the bare value)
//   - openconnector/src/registry.js — same pattern

import DashboardView from './views/dashboard/Dashboard.vue'
import CatalogDetailPageView from './views/catalogi/CatalogDetailPage.vue'
import WooBatchDetailView from './views/woo/WooBatchDetail.vue'
import FederationDirectory from './views/directory/FederationDirectory.vue'
import FederationSearch from './views/search/FederationSearch.vue'
import ThemePreviewWidget from './components/widgets/ThemePreviewWidget.vue'
import {
	CnFederationStatus,
	CnFileManager,
	CnRelationshipGraph,
	CnTreeView,
} from '@conduction/nextcloud-vue'

export default {
	// --- Page components (referenced by `component` in manifest pages). ---
	DashboardView,
	CatalogDetailPageView,
	WooBatchDetailView,
	CnFederationStatus,
	FederationDirectory,
	FederationSearch,

	// --- Detail-page widgets (referenced by `widgetKey` in manifest pages). ---
	// NOTE: this map is passed to CnAppRoot as the `customComponents` prop,
	// which provides `cnCustomComponents` — a DIFFERENT inject than
	// `cnRegistry` (fed by a `registry` prop this app never sets). CnWidgetGrid
	// (the page-level `widgets[]`/`widgetKey` renderer) only ever resolves a
	// key against `cnRegistry` → BUILT_IN_WIDGETS → the shared dashboard
	// widget catalog — never against this map. The four keys below are
	// therefore NOT resolved by CnWidgetGrid from here; they render only
	// because main.js also registers them into the shared catalog via
	// `registerDashboardWidget`, which IS in CnWidgetGrid's fallback chain.
	// This map's entries are effectively unreachable dead code for these
	// four keys but are left in place (harmless) for any future consumer
	// that resolves widgetKeys against `customComponents` directly.
	'theme-preview': ThemePreviewWidget,
	'tree-view': CnTreeView,
	'relationship-graph': CnRelationshipGraph,
	'file-manager': CnFileManager,
}
