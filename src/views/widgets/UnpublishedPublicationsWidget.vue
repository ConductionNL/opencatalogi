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
			<NcEmptyContent :title="t('opencatalogi', 'No concept publications found')">
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
 * UnpublishedPublicationsWidget — dashboard widget listing unpublished publications.
 *
 * @spec openspec/changes/retrofit-2026-05-25-dashboard/tasks.md#task-3
 */
export default {
	name: 'UnpublishedPublicationsWidget',
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
					text: 'Publicatie bekijken',
					icon: 'icon-open-in-app',
				},
			},
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		items() {
			return objectStore.getCollection('publication').results
				.filter((publication) => publication.status === 'Concept')
				.map((publication) => ({
					id: publication.id,
					mainText: publication.title,
					subText: publication.summary,
					avatarUrl: getTheme() === 'light' ? '/apps-extra/opencatalogi/img/database-eye-outline.svg' : '/apps-extra/opencatalogi/img/database-eye-outline_light.svg',
				}))
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Handle showing a publication
		 * @param {object} item - The publication item to show
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		onShow(item) {
			// navigationStore.setSelected('publication')
			// navigationStore.setSelectedCatalogus(item.id)
			// window.open('/index.php/apps/opencatalogi', '_self')
		},
		/**
		 * Fetch the publication data
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		async fetchData() {
			this.loading = true
			await objectStore.fetchCollection('publication')
			this.loading = false
		},
	},
}
</script>
