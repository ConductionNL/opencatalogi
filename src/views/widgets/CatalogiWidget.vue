<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore } from '../../store/store.js'
</script>

<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('opencatalogi', 'No catalogs found')">
				<template #icon>
					<FolderIcon />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
// Components
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'

// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'

import { getTheme } from '../../services/getTheme.js'

/**
 * CatalogiWidget — Nextcloud dashboard widget listing catalogs.
 *
 * @spec openspec/changes/retrofit-2026-05-25-catalogs/tasks.md#task-4
 */
export default {
	name: 'CatalogiWidget',
	components: {
		NcDashboardWidget,
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
			itemMenu: {
				show: {
					text: 'View catalog',
					icon: 'icon-open-in-app',
				},
			},
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
				avatarUrl: getTheme() === 'light' ? '/apps-extra/opencatalogi/img/database-outline.svg' : '/apps-extra/opencatalogi/img/database-outline_light.svg',
			}))
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Handle showing a catalog
		 * @param {object} item - The catalog item to show
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		onShow(item) {
			window.location.href = `/index.php/apps/opencatalogi/publications/${item.id}`
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
