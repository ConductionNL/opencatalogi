<template>
	<NcContent app-name="opencatalogi">
		<MainMenu @open-settings="settingsOpen = true" />
		<NcAppContent>
			<template #default>
				<router-view />
			</template>
		</NcAppContent>
		<router-view name="sidebar" />

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
			v-if="objectSidebarState.active"
			:object-type="objectSidebarState.objectType"
			:object-id="objectSidebarState.objectId"
			:title="objectSidebarState.title"
			:subtitle="objectSidebarState.subtitle"
			:register="objectSidebarState.register"
			:schema="objectSidebarState.schema"
			:hidden-tabs="objectSidebarState.hiddenTabs"
			:open.sync="objectSidebarState.open" />

		<Modals />
		<Dialogs />
		<UserSettings :open="settingsOpen" @update:open="settingsOpen = $event" />
	</NcContent>
</template>

<script>

import Vue from 'vue'
import { NcContent, NcAppContent } from '@nextcloud/vue'
import { CnObjectSidebar, CnIndexSidebar } from '@conduction/nextcloud-vue'
import MainMenu from './navigation/MainMenu.vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import UserSettings from './views/settings/UserSettings.vue'
import { objectStore } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		CnObjectSidebar,
		CnIndexSidebar,
		MainMenu,
		Modals,
		Dialogs,
		UserSettings,
	},
	provide() {
		return {
			objectSidebarState: this.objectSidebarState,
			sidebarState: this.sidebarState,
		}
	},
	data() {
		return {
			settingsOpen: false,
			objectSidebarState: {
				active: false,
				open: true,
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				schema: '',
				hiddenTabs: [],
			},
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
	async mounted() {
		await objectStore.preloadCollections()
	},
	methods: {
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
