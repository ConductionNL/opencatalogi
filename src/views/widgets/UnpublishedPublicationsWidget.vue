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
		row-icon="DatabaseEyeOutline"
		@row-click="onRowClick">
		<template #empty>
			<NcEmptyContent :title="t('opencatalogi', 'No concept publications found')">
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
import DatabaseEyeOutline from 'vue-material-design-icons/DatabaseEyeOutline.vue'

import { LIST_COLUMNS, navigateTo } from './widgetTable.js'

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
			return objectStore.getCollection('publication').results
				.filter((publication) => publication.status === 'Concept')
				.map((publication) => ({
					id: publication.id,
					mainText: publication.title,
					subText: publication.summary,
					// Keep the source object on the row so a click can
					// resolve the owning catalog's slug for the deep link.
					publication,
				}))
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Open the clicked publication's detail page in the same tab.
		 *
		 * Resolves the publication's catalog (id or embedded object) against
		 * the fetched catalog collection to obtain the slug required by the
		 * `/publications/{catalogSlug}/{id}` route. When no slug can be
		 * resolved the click is a no-op.
		 *
		 * @param {object} row - The clicked row (a shaped publication item).
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		onRowClick(row) {
			const publication = row.publication
			const catalogId = publication?.catalog?.id || publication?.catalog
			const catalogs = objectStore.getCollection('catalog')?.results || []
			const matchedCatalog = catalogs.find((c) => (c?.id?.toString() || '') === (catalogId?.toString() || ''))
			const slug = matchedCatalog?.slug || publication?.catalog?.slug
			if (!slug || !publication?.id) return
			navigateTo(generateUrl(`/apps/opencatalogi/publications/${slug}/${publication.id}`))
		},
		/**
		 * Fetch the publication data (and the catalog collection used to
		 * resolve catalog slugs for row-click deep links).
		 * @return {Promise<void>}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-dashboard-widgets/tasks.md#task-3 */
		async fetchData() {
			this.loading = true
			await Promise.all([
				objectStore.fetchCollection('publication'),
				objectStore.fetchCollection('catalog'),
			])
			this.loading = false
		},
	},
}
</script>
