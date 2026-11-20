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
		rowIcon="DatabaseEyeOutline"
		@rowClick="onRowClick">
		<template #empty>
			<NcEmptyContent
				:title="t('opencatalogi', 'No concept publications found')">
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
import DatabaseEyeOutline from 'vue-material-design-icons/DatabaseEyeOutline.vue'
// Icons
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import { fetchUnpublished, publicationRow, publicationUrl } from './unpublishedOverview.js'
import { LIST_WIDGET_OPTIONS, navigateTo } from './widgetTable.js'

// The row's leading icon renders through CnDataTable's shared CnIcon
// registry; MDI icons use currentColor, so light/dark theming is automatic
// (replacing the old getTheme() light/dark SVG-url swap).
registerIcons({ DatabaseEyeOutline })

/**
 * UnpublishedPublicationsWidget — dashboard widget listing unpublished publications.
 *
 * Renders the universal CnDataTable list-widget pattern (ADR-049); a row
 * click deep-links to the publication's detail page
 * (`/publications/{catalogSlug}/{id}`, served by `ui#publicationsPage`),
 * resolving the catalog slug the same way DashboardSideBar does.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'UnpublishedPublicationsWidget',
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
		 * The not yet public publications the reader can see (REQ-PUO-002).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
		 */
		items() {
			return this.overview.map(publicationRow)
		},
	},

	methods: {
		/**
		 * Open the clicked publication's detail page; a row without a catalogue does nothing.
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
		 * Read the publications list of the unpublished overview.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
		 */
		async fetchData() {
			this.loading = true
			this.overview = await fetchUnpublished('publications')
			this.loading = false
		},
	},
}
</script>
