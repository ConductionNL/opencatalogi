<!--
  - SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { translate as t } from '@nextcloud/l10n'
</script>

<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		rowIcon="FileOutline"
		@rowClick="onRowClick">
		<template #empty>
			<NcEmptyContent
				:title="t('opencatalogi', 'No unpublished documents')">
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
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import { documentRow, fetchUnpublished, publicationUrl } from './unpublishedOverview.js'
import { LIST_WIDGET_OPTIONS, navigateTo } from './widgetTable.js'

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
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'UnpublishedAttachmentsWidget',
	components: {
		CnDataTable,
		NcEmptyContent,
	},

	...LIST_WIDGET_OPTIONS,

	data() {
		return { ...LIST_WIDGET_OPTIONS.data(), overview: [] }
	},

	computed: {
		/**
		 * The documents of the reader's publications that are not public (REQ-PUO-002).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
		 */
		items() {
			return this.overview.map(documentRow)
		},
	},

	methods: {
		/**
		 * Open the publication the clicked document belongs to.
		 *
		 * @param {object} row The clicked row.
		 * @return {void}
		 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
		 */
		onRowClick(row) {
			const url = publicationUrl(row.item)
			if (url !== null) {
				navigateTo(url)
			}
		},

		/**
		 * Read the documents list of the unpublished overview.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
		 */
		async fetchData() {
			this.loading = true
			this.overview = await fetchUnpublished('documents')
			this.loading = false
		},
	},
}
</script>

