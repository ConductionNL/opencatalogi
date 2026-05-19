// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// 5-kind component registry for v2 manifest (per hydra ADR-036).
//
// The v1 customComponents.js map is preserved alongside this file for
// backward-compat during the migration window. CnAppRoot accepts both
// props; the v2 renderer emits a one-shot deprecation warning when both
// are present and the manifest is v2.
//
// Lib gaps documented here (see also src/manifest.json _note fields):
//   - dashboard #widget-* slot template pass-through (opencatalogi's
//     Dashboard.vue uses custom chart/stats widget slots not yet
//     expressible as a 5-kind widget registration)
//   - Named-view sidebar (SearchSideBar uses Vue Router named views;
//     CnAppRoot routing doesn't support this yet)
//
// References:
//   - hydra ADR-036
//   - nextcloud-app-template scaffold-v2 (#44) — canonical layout
//   - procest #512 / mydash #206 — first reference migrations

import DashboardView from './views/dashboard/Dashboard.vue'
import CatalogiIndexView from './views/catalogi/CatalogiIndex.vue'
import CatalogDetailPageView from './views/catalogi/CatalogDetailPage.vue'
import PublicationIndexView from './views/publications/PublicationIndex.vue'
import PublicationDetailPageView from './views/publications/PublicationDetailPage.vue'
import SearchIndexView from './views/search/SearchIndex.vue'
import OrganizationIndexView from './views/organizations/OrganizationIndex.vue'
import ThemeIndexView from './views/themes/ThemeIndex.vue'
import ThemeDetailPageView from './views/themes/ThemeDetailPage.vue'
import GlossaryIndexView from './views/glossary/GlossaryIndex.vue'
import GlossaryDetailPageView from './views/glossary/GlossaryDetailPage.vue'
import PageIndexView from './views/pages/PageIndex.vue'
import PageDetailPageView from './views/pages/PageDetailPage.vue'
import MenuIndexView from './views/menus/MenuIndex.vue'
import MenuDetailPageView from './views/menus/MenuDetailPage.vue'
import DirectoryIndexView from './views/directory/DirectoryIndex.vue'

export default {
	// All entries are kind:'page' — bespoke per-feature views. Future
	// cleanup: as lib primitives (named-view sidebar, dashboard slot
	// pass-through) land, these can shrink toward zero.
	DashboardView: { kind: 'page', component: DashboardView },
	CatalogiIndexView: { kind: 'page', component: CatalogiIndexView },
	CatalogDetailPageView: { kind: 'page', component: CatalogDetailPageView },
	PublicationIndexView: { kind: 'page', component: PublicationIndexView },
	PublicationDetailPageView: { kind: 'page', component: PublicationDetailPageView },
	SearchIndexView: { kind: 'page', component: SearchIndexView },
	OrganizationIndexView: { kind: 'page', component: OrganizationIndexView },
	ThemeIndexView: { kind: 'page', component: ThemeIndexView },
	ThemeDetailPageView: { kind: 'page', component: ThemeDetailPageView },
	GlossaryIndexView: { kind: 'page', component: GlossaryIndexView },
	GlossaryDetailPageView: { kind: 'page', component: GlossaryDetailPageView },
	PageIndexView: { kind: 'page', component: PageIndexView },
	PageDetailPageView: { kind: 'page', component: PageDetailPageView },
	MenuIndexView: { kind: 'page', component: MenuIndexView },
	MenuDetailPageView: { kind: 'page', component: MenuDetailPageView },
	DirectoryIndexView: { kind: 'page', component: DirectoryIndexView },
}
