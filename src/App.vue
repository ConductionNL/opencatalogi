<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:registry="registry"
		:page-types="pageTypes"
		app-id="opencatalogi"
		:translate="translateForApp"
		:permissions="permissions" />
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { objectStore } from './store/store.js'

export default {
	name: 'App',
	components: {
		CnAppRoot,
	},

	provide() {
		return {
			// Provide/inject channel for custom components that use the object
			// sidebar (CnDetailPage). Mirrors the procest pattern.
			objectSidebarState: this.objectSidebarState,
			// Legacy alias kept for existing custom components that inject
			// `sidebarState` (SearchIndex, CatalogiIndex, etc.).
			sidebarState: this.objectSidebarState,
		}
	},

	props: {
		manifest: {
			type: Object,
			required: true,
		},
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * 5-kind component registry (v2 manifest pattern per hydra ADR-036).
		 * Each entry: { kind, component, ...kindMetadata }.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
		pageTypes: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			objectSidebarState: Vue.observable({
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
			const base = window.OC?.currentUser?.permissions ?? []
			// CnAppNav's permission filter is an array-includes check; Nextcloud
			// does not put the boolean admin flag into the permissions array, so
			// we inject it here for manifest entries gated on permission: "admin".
			const isAdmin = typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
			return isAdmin ? [...base, 'admin'] : base
		},
	},

	async created() {
		// Pre-load catalog collection so the MainMenu nav items and
		// PublicationIndex route can resolve the active catalog slug on first render.
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
	},
}
</script>
