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
import {
	CnFederationStatus,
	CnFileManager,
	CnRelationshipGraph,
	CnThemePreview,
	CnTreeView,
} from '@conduction/nextcloud-vue'

export default {
	// --- Page components (referenced by `component` in manifest pages). ---
	DashboardView,
	CatalogDetailPageView,
	CnFederationStatus,

	// --- Detail-page widgets (referenced by `widgetKey` in manifest pages). ---
	'theme-preview': CnThemePreview,
	'tree-view': CnTreeView,
	'relationship-graph': CnRelationshipGraph,
	'file-manager': CnFileManager,
}
