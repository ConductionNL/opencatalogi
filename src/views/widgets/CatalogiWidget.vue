<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore } from '../../store/store.js'
</script>

<template>
	<CnDataTable :rows="items"
		:columns="columns"
		:loading="loading"
		hide-header
		borderless
		row-icon="DatabaseOutline"
		@row-click="onRowClick">
		<template #empty>
			<NcEmptyContent :title="t('opencatalogi', 'No catalogs found')">
				<template #icon>
					<FolderIcon />
				</template>
			</NcEmptyContent>
		</template>
	</CnDataTable>
</template>

<script>
// Components
import { CnDataTable, registerIcons } from '@conduction/nextcloud-vue'
import { NcEmptyContent } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'

import { LIST_COLUMNS, navigateTo } from './widgetTable.js'

// The row's leading icon renders through CnDataTable's shared CnIcon
// registry; MDI icons use currentColor, so light/dark theming is automatic
// (replacing the old getTheme() light/dark SVG-url swap).
registerIcons({ DatabaseOutline })

/**
 * CatalogiWidget — Nextcloud dashboard widget listing catalogs.
 *
 * Renders the universal CnDataTable list-widget pattern (ADR-049); a row
 * click opens the catalog's publications listing in the same tab.
 *
 * @spec openspec/specs/catalogs/spec.md
 */
export default {
	name: 'CatalogiWidget',
	components: {
		CnDataTable,
		NcEmptyContent,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			columns: LIST_COLUMNS,
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		items() {
			return objectStore.getCollection('catalog').results.map((catalog) => ({
				// expecting that slug exists on the catalog object
				id: catalog.slug,
				mainText: catalog.title,
				subText: catalog.summary,
			}))
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Open the clicked catalog's publications listing in the same tab.
		 * @param {object} row - The clicked row (a shaped catalog item).
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		onRowClick(row) {
			navigateTo(generateUrl(`/apps/opencatalogi/publications/${row.id}`))
		},
		/**
		 * Fetch the catalog data
		 * @param {string|null} search - Optional search term
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		async fetchData(search = null) {
			this.loading = true
			await objectStore.fetchCollection('catalog', search)
			this.loading = false
		},
	},
}
</script>
