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
//   2. Built-in widget types        (object-table, form-renderer, …)
//   3. customComponents (this file) ← consumer-injected components
//
// Post-beta.63 migration: most index/detail/search pages flipped to
// typed manifest entries (config.actionToggles, type:'search',
// widget-driven detail bodies). Only DashboardView (custom analytics
// chart slots) and CatalogDetailPageView (bespoke header / sidebar tabs)
// remain wrapper views — see their _note in manifest.json. The
// Directory page now mounts the lib's CnFederationStatus directly.

import DashboardView from './views/dashboard/Dashboard.vue'
import CatalogDetailPageView from './views/catalogi/CatalogDetailPage.vue'
import { CnFederationStatus } from '@conduction/nextcloud-vue'

export default {
	// --- Dashboard (custom chart/stats widgets, named slot templates). ---
	DashboardView,

	// --- Catalog detail (bespoke header + sidebar tabs not yet expressible). ---
	CatalogDetailPageView,

	// --- Federation status (lib component mounted as a page-level custom). ---
	CnFederationStatus,
}
