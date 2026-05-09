<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 OpenCatalogi app shell. Mounts CnAppRoot with the bundled manifest and
 the customComponents registry; provides the `objectSidebarState` +
 `sidebarState` channels so detail/index pages can drive the host-
 rendered CnObjectSidebar / CnIndexSidebar via the #sidebar slot. Modals,
 Dialogs, and UserSettings keep their existing wiring through the
 `navigationStore` global state.

 @spec openspec/changes/opencatalogi-manifest-v1/spec/REQ-OCMV1-6
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:page-types="pageTypes"
		app-id="opencatalogi"
		:translate="translateForApp"
		:permissions="permissions">
		<template #sidebar>
			<!-- Index sidebar (search/filter/columns — controlled by useListView via sidebarState) -->
			<CnIndexSidebar
				v-if="sidebarState.active && !objectSidebarState.active"
				:schema="sidebarState.schema"
				:visible-columns="sidebarState.visibleColumns"
				:search-value="sidebarState.searchValue"
				:active-filters="sidebarState.activeFilters"
				:facet-data="sidebarState.facetData"
				:open="sidebarState.open"
				@update:open="sidebarState.open = $event"
				@search="onSidebarSearch"
				@columns-change="onSidebarColumnsChange"
				@filter-change="onSidebarFilterChange" />

			<!-- Object sidebar (files/notes/tags/tasks/audit trail — controlled by CnDetailPage) -->
			<CnObjectSidebar
				v-else-if="objectSidebarState.active"
				:object-type="objectSidebarState.objectType"
				:object-id="objectSidebarState.objectId"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:hidden-tabs="objectSidebarState.hiddenTabs"
				:open.sync="objectSidebarState.open" />

			<!-- Search sidebar — preserved via in-template mount because the
			     manifest cannot express vue-router named views in v1.
			     Mounts only on /search, mirroring the previous router-named
			     view contract. -->
			<SearchSideBar
				v-else-if="$route.name === 'Search'"
				:open="searchSidebarOpen"
				@update:open="searchSidebarOpen = $event" />
		</template>

		<!-- Host components that drive the global navigationStore-mediated
		     modal/dialog stack. Sit alongside CnAppRoot's internal slots
		     and stay mounted across route transitions. -->
		<Modals />
		<Dialogs />
		<UserSettings :open="settingsOpen" @update:open="settingsOpen = $event" />
	</CnAppRoot>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import {
	CnAppRoot,
	CnObjectSidebar,
	CnIndexSidebar,
} from '@conduction/nextcloud-vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import UserSettings from './views/settings/UserSettings.vue'
import SearchSideBar from './sidebars/search/SearchSideBar.vue'
import { objectStore } from './store/store.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
		CnIndexSidebar,
		Modals,
		Dialogs,
		UserSettings,
		SearchSideBar,
	},

	provide() {
		return {
			// Channel for CnDetailPage → host-rendered CnObjectSidebar.
			// Vue.observable makes the plain object reactive for Vue 2.
			objectSidebarState: this.objectSidebarState,
			// Channel for CnIndexPage → host-rendered CnIndexSidebar.
			sidebarState: this.sidebarState,
		}
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * Registry of consumer-injected components used by:
		 *   - `type: "custom"` pages (`page.component`)
		 *   - `headerComponent` / `actionsComponent` slot overrides
		 *   - `pages[].config.sidebarTabs[].component` (detail tab tabs)
		 *   - `pages[].config.sections[].component` (settings rich sections)
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances via
		 * provide/inject.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			settingsOpen: false,
			searchSidebarOpen: true,
			objectSidebarState: Vue.observable({
				active: false,
				open: true,
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				schema: '',
				hiddenTabs: [],
			}),
			sidebarState: Vue.observable({
				active: false,
				open: true,
				schema: null,
				visibleColumns: null,
				searchValue: '',
				activeFilters: {},
				facetData: {},
				onSearch: null,
				onColumnsChange: null,
				onFilterChange: null,
			}),
		}
	},

	computed: {
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},

	async mounted() {
		// Pinia stores still need to come up so legacy custom components
		// (every type:"custom" page, the modals, dialogs) keep working
		// through the transition. CnAppRoot itself doesn't depend on them.
		await objectStore.preloadCollections()
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so
		 * the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('opencatalogi', key)
		},
		onSidebarSearch(value) {
			this.sidebarState.searchValue = value
			if (typeof this.sidebarState.onSearch === 'function') {
				this.sidebarState.onSearch(value)
			}
		},
		onSidebarColumnsChange(columns) {
			this.sidebarState.visibleColumns = columns
			if (typeof this.sidebarState.onColumnsChange === 'function') {
				this.sidebarState.onColumnsChange(columns)
			}
		},
		onSidebarFilterChange(filter) {
			if (typeof this.sidebarState.onFilterChange === 'function') {
				this.sidebarState.onFilterChange(filter)
			}
		},
	},
}
</script>
