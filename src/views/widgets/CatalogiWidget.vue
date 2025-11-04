<script setup>
import { objectStore } from '../../store/store.js'
</script>

<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('opencatalogi', 'Geen catalogi gevonden')">
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
		onShow(item) {
			window.location.href = `/index.php/apps/opencatalogi/publications/${item.id}`
		},
		/**
		 * Fetch the catalog data
		 * @param {string|null} search - Optional search term
		 * @return {Promise<void>}
		 */
		async fetchData(search = null) {
			this.loading = true
			await objectStore.fetchCollection('catalog', search)
			this.loading = false
		},
	},
}
</script>
