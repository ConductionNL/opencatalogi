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
		row-icon="FileOutline">
		<template #empty>
			<NcEmptyContent :title="t('opencatalogi', 'No concept attachments found')">
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

// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'

import { LIST_COLUMNS } from './widgetTable.js'

// The row's leading icon renders through CnDataTable's shared CnIcon
// registry; MDI icons use currentColor, so light/dark theming is automatic
// (replacing the old getTheme() light/dark SVG-url swap).
registerIcons({ FileOutline })

/**
 * UnpublishedAttachmentsWidget — dashboard widget listing unpublished attachments.
 *
 * Renders the universal CnDataTable list-widget pattern (ADR-049). The list
 * is non-interactive (no row navigation), matching the previous
 * NcDashboardWidget behaviour with its empty item menu.
 *
 * @spec openspec/changes/retrofit-2026-05-25-dashboard/tasks.md#task-3
 */
export default {
	name: 'UnpublishedAttachmentsWidget',
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
			return objectStore.getCollection('attachment').results
				.filter((attachment) => attachment.status === 'Concept')
				.map((attachment) => ({
					id: attachment.id,
					mainText: attachment.title,
					subText: attachment.summary,
				}))
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Fetch the attachment data
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		async fetchData() {
			this.loading = true
			await objectStore.fetchCollection('attachment')
			this.loading = false
		},
	},
}
</script>

<style scoped>
/* This list is non-interactive (no row navigation); suppress the shared
   table's pointer cursor so rows don't advertise a click that does nothing. */
:deep(.cn-table-row) {
	cursor: default;
}
</style>
