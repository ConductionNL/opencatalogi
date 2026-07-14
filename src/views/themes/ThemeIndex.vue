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
		:show-add="isAdmin"
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
		<template #below-header>
			<NcNoteCard v-if="loaded && !isAdmin" type="info">
				{{ t('opencatalogi', 'This page is read-only. Only administrators can create, edit, or delete entries here.') }}
			</NcNoteCard>
		</template>

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
				<NcActionButton v-if="isAdmin" close-after-click @click="editTheme(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="copyTheme(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="deleteTheme(row)">
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
import { inject } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useListView, CnIndexPage } from '@conduction/nextcloud-vue'
import { objectStore, navigationStore } from '../../store/store.js'
import { NcActions, NcActionButton, NcNoteCard } from '@nextcloud/vue'
import { useIsAdmin } from '../../composables/useIsAdmin.js'
import { resolveObjectId } from '../../services/resolveObjectId.js'
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
		NcNoteCard,
		DotsHorizontal,
		Eye,
		Pencil,
		ContentCopy,
		TrashCanOutline,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const { schema, sortKey, sortOrder, visibleColumns, onSort, onPageChange, onPageSizeChange, refresh } = useListView('theme', {
			sidebarState,
			objectStore,
		})
		const { isAdmin, loaded } = useIsAdmin()
		return { schema, sortKey, sortOrder, visibleColumns, onSort, onPageChange, onPageSizeChange, refresh, objectStore, navigationStore, isAdmin, loaded }
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
			const id = resolveObjectId(row)
			if (id) {
				this.$router.push({ name: 'ThemeDetail', params: { id: String(id) } })
				return
			}
			// eslint-disable-next-line no-console
			console.warn('[opencatalogi] onRowClick: no id resolvable from row', row)
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
