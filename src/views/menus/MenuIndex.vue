<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Menus')"
		:description="t('opencatalogi', 'Manage your navigation menus and menu items')"
		:show-title="true"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('menu')"
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
		:add-label="t('opencatalogi', 'Add Menu')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No menus found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="handleRefresh"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<!-- Custom column: menu items count -->
		<template #column-items="{ row }">
			{{ row.items?.length || 0 }}
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
				<NcActionButton close-after-click @click="editMenu(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="addMenuItem(row)">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('opencatalogi', 'Add Item') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="copyMenu(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="deleteMenu(row)">
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
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'MenuIndex',
	components: {
		CnIndexPage,
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Pencil,
		Plus,
		ContentCopy,
		TrashCanOutline,
	},
	data() {
		return {
			selectedIds: [],
			viewMode: 'cards',
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true },
				{ key: 'position', label: t('opencatalogi', 'Position'), sortable: true },
				{ key: 'items', label: t('opencatalogi', 'Menu Items') },
				{ key: 'updatedAt', label: t('opencatalogi', 'Last Updated'), sortable: true },
			]
		},
		currentObjects() {
			const collection = objectStore.getCollection('menu')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('menu')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	mounted() {
		objectStore.fetchCollection('menu')
	},
	methods: {
		onAdd() {
			objectStore.clearActiveObject('menu')
			navigationStore.setModal('viewMenu')
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await objectStore.fetchCollection('menu')
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChange(page) {
			objectStore.fetchCollection('menu', { _page: page })
		},
		onPageSizeChange(size) {
			objectStore.fetchCollection('menu', { _page: 1, _limit: size })
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		onRowClick(row) {
			objectStore.setActiveObject('menu', row)
			navigationStore.setModal('viewMenu')
		},
		editMenu(menu) {
			objectStore.setActiveObject('menu', menu)
			navigationStore.setModal('viewMenu')
		},
		addMenuItem(menu) {
			objectStore.setActiveObject('menu', menu)
			navigationStore.setModal('menuItemForm')
		},
		copyMenu(menu) {
			objectStore.setActiveObject('menu', menu)
			navigationStore.setDialog('copyObject', { objectType: 'menu', dialogTitle: 'Menu' })
		},
		deleteMenu(menu) {
			objectStore.setActiveObject('menu', menu)
			navigationStore.setDialog('deleteObject', { objectType: 'menu', dialogTitle: 'Menu' })
		},
	},
}
</script>
