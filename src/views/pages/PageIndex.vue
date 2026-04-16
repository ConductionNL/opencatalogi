<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Pages')"
		:description="t('opencatalogi', 'Manage your content pages and their components')"
		:show-title="true"
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
		:add-label="t('opencatalogi', 'Add Page')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No pages found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="handleRefresh"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<template #column-contents="{ row }">{{ row.contents?.length || 0 }}</template>
		<template #column-updatedAt="{ row }">{{ row.updatedAt ? new Date(row.updatedAt).toLocaleDateString() : '-' }}</template>
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon><DotsHorizontal :size="20" /></template>
				<NcActionButton close-after-click @click="editPage(row)"><template #icon><Pencil :size="20" /></template>{{ t('opencatalogi', 'Edit') }}</NcActionButton>
				<NcActionButton close-after-click @click="copyPage(row)"><template #icon><ContentCopy :size="20" /></template>{{ t('opencatalogi', 'Copy') }}</NcActionButton>
				<NcActionButton close-after-click @click="deletePage(row)"><template #icon><TrashCanOutline :size="20" /></template>{{ t('opencatalogi', 'Delete') }}</NcActionButton>
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
	components: { CnIndexPage, NcActions, NcActionButton, DotsHorizontal, Pencil, ContentCopy, TrashCanOutline },
	data() { return { selectedIds: [], viewMode: 'cards', isRefreshing: false } },
	computed: {
		tableColumns() { return [{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true }, { key: 'slug', label: t('opencatalogi', 'Slug'), sortable: true }, { key: 'contents', label: t('opencatalogi', 'Content Items') }, { key: 'updatedAt', label: t('opencatalogi', 'Last Updated'), sortable: true }] },
		currentObjects() { const c = objectStore.getCollection('page'); return Array.isArray(c) ? c : c?.results || [] },
		currentPagination() { return objectStore.getPagination('page') || { total: 0, page: 1, pages: 1, limit: 20 } },
	},
	mounted() { objectStore.fetchCollection('page') },
	methods: {
		onAdd() { objectStore.clearActiveObject('page'); navigationStore.setModal('viewPage') },
		async handleRefresh() { this.isRefreshing = true; try { await objectStore.fetchCollection('page') } finally { this.isRefreshing = false } },
		onPageChange(page) { objectStore.fetchCollection('page', { _page: page }) },
		onPageSizeChange(size) { objectStore.fetchCollection('page', { _page: 1, _limit: size }) },
		onSelect(ids) { this.selectedIds = ids },
		onRowClick(row) { objectStore.setActiveObject('page', row); navigationStore.setModal('viewPage') },
		editPage(page) { objectStore.setActiveObject('page', page); navigationStore.setModal('viewPage') },
		copyPage(page) { objectStore.setActiveObject('page', page); navigationStore.setDialog('copyObject', { objectType: 'page', dialogTitle: 'Pagina' }) },
		deletePage(page) { objectStore.setActiveObject('page', page); navigationStore.setDialog('deleteObject', { objectType: 'page', dialogTitle: 'Pagina' }) },
	},
}
</script>
