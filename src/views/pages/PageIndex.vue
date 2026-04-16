<script setup>
import { inject } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useListView } from '@conduction/nextcloud-vue'
import { objectStore, navigationStore } from '../../store/store.js'

const sidebarState = inject('sidebarState', null)
const { schema, sortKey, sortOrder, visibleColumns, onSort, onPageChange, onPageSizeChange, refresh } = useListView('page', {
	sidebarState,
	objectStore,
})
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Pages')"
		:description="t('opencatalogi', 'Manage your content pages and their components')"
		:show-title="true"
		:schema="schema"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('page')"
		:selectable="true"
		:selected-ids="selectedIds"
		:show-view-toggle="true"
		:show-edit-action="false"
		:show-copy-action="false"
		:show-delete-action="false"
		:show-mass-import="false"
		:show-mass-export="false"
		:show-mass-copy="false"
		:show-mass-delete="false"
		:view-mode="viewMode"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:include-columns="visibleColumns"
		:add-label="t('opencatalogi', 'Add Page')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No pages found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="refresh"
		@sort="onSort"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<!-- Custom column: content items count -->
		<template #column-contents="{ row }">
			{{ row.contents?.length || 0 }}
		</template>

		<!-- Custom column: updated date -->
		<template #column-updatedAt="{ row }">
			{{ row.updatedAt ? new Date(row.updatedAt).toLocaleDateString() : '-' }}
		</template>

		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="editPage(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="copyPage(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="deletePage(row)">
					<template #icon>
						<TrashCanOutline :size="20" />
					</template>
					{{ t('opencatalogi', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</template>
	</CnIndexPage>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'PageIndex',
	components: {
		CnIndexPage,
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		ContentCopy,
		TrashCanOutline,
	},
	data() {
		return {
			selectedIds: [],
			viewMode: 'table',
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true },
				{ key: 'slug', label: t('opencatalogi', 'Slug'), sortable: true },
				{ key: 'contents', label: t('opencatalogi', 'Content Items') },
				{ key: 'updatedAt', label: t('opencatalogi', 'Last Updated'), sortable: true },
			]
		},
		currentObjects() {
			const collection = objectStore.getCollection('page')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('page')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	methods: {
		onAdd() {
			objectStore.clearActiveObject('page')
			navigationStore.setModal('viewPage')
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		onRowClick(row) {
			const id = row?.['@self']?.id || row?.id
			if (id) {
				this.$router.push({ name: 'PageDetail', params: { id } })
			}
		},
		editPage(page) {
			const id = page?.['@self']?.id || page?.id
			if (id) {
				this.$router.push({ name: 'PageDetail', params: { id } })
			}
		},
		copyPage(page) {
			objectStore.setActiveObject('page', page)
			navigationStore.setDialog('copyObject', { objectType: 'page', dialogTitle: 'Pagina' })
		},
		deletePage(page) {
			objectStore.setActiveObject('page', page)
			navigationStore.setDialog('deleteObject', { objectType: 'page', dialogTitle: 'Pagina' })
		},
	},
}
</script>
