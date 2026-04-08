<script setup>
import { inject } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useListView } from '@conduction/nextcloud-vue'
import { objectStore, navigationStore } from '../../store/store.js'

const sidebarState = inject('sidebarState', null)
const { schema, sortKey, sortOrder, visibleColumns, onSort, onPageChange, onPageSizeChange, refresh } = useListView('theme', {
	sidebarState,
	objectStore,
})
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Themes')"
		:description="t('opencatalogi', 'Manage your website themes and visual styling')"
		:show-title="true"
		:schema="schema"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('theme')"
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
		:add-label="t('opencatalogi', 'Add Theme')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No themes found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="refresh"
		@sort="onSort"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="viewTheme(row)">
					<template #icon>
						<Eye :size="20" />
					</template>
					{{ t('opencatalogi', 'View') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="editTheme(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="copyTheme(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="deleteTheme(row)">
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
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'ThemeIndex',
	components: {
		CnIndexPage,
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Eye,
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
				{ key: 'status', label: t('opencatalogi', 'Status'), sortable: true },
				{ key: 'summary', label: t('opencatalogi', 'Summary') },
			]
		},
		currentObjects() {
			const collection = objectStore.getCollection('theme')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('theme')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	methods: {
		onAdd() {
			objectStore.clearActiveObject('theme')
			navigationStore.setModal('theme')
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		onRowClick(row) {
			const id = row?.['@self']?.id || row?.id
			if (id) {
				this.$router.push({ name: 'ThemeDetail', params: { id } })
			}
		},
		viewTheme(theme) {
			const id = theme?.['@self']?.id || theme?.id
			if (id) {
				this.$router.push({ name: 'ThemeDetail', params: { id } })
			}
		},
		editTheme(theme) {
			objectStore.setActiveObject('theme', theme)
			navigationStore.setModal('theme')
		},
		copyTheme(theme) {
			objectStore.setActiveObject('theme', theme)
			navigationStore.setDialog('copyObject', { objectType: 'theme', dialogTitle: 'Theme' })
		},
		deleteTheme(theme) {
			objectStore.setActiveObject('theme', theme)
			navigationStore.setDialog('deleteObject', { objectType: 'theme', dialogTitle: 'Theme' })
		},
	},
}
</script>
