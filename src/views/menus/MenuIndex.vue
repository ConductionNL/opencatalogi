<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Menus')"
		:description="t('opencatalogi', 'Manage your navigation menus and menu items')"
		:show-title="true"
		:schema="menuSchema"
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
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:include-columns="visibleColumns"
		:add-label="t('opencatalogi', 'Add Menu')"
		:show-add="isAdmin"
		row-key="id"
		:empty-text="t('opencatalogi', 'No menus found')"
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
				<NcActionButton v-if="isAdmin" close-after-click @click="editMenu(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="addMenuItem(row)">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('opencatalogi', 'Add Item') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="copyMenu(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="deleteMenu(row)">
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
import { buildMenuItemIconCatalogues } from '../../modals/menuItem/menuItemIconCatalogues.js'
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
		NcNoteCard,
		DotsHorizontal,
		Pencil,
		Plus,
		ContentCopy,
		TrashCanOutline,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const { schema, sortKey, sortOrder, visibleColumns, onSort, onPageChange, onPageSizeChange, refresh } = useListView('menu', {
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
		// Augment the OpenRegister menu schema with an `icon` property rendered
		// by the shared CnIconPicker (via CnFormDialog's schema-driven
		// `widget: 'icon'`). Sources: MDI (default), plus FontAwesome and an
		// OpenGemeenten sample supplied as consumer catalogues; custom SVG enabled.
		menuSchema() {
			const base = this.schema || {}
			return {
				...base,
				properties: {
					...(base.properties || {}),
					icon: {
						type: 'string',
						title: t('opencatalogi', 'Icon'),
						description: t('opencatalogi', 'Pick an icon (Material / FontAwesome / OpenGemeenten) or paste custom SVG.'),
						widget: 'icon',
						iconSources: ['mdi', 'fontawesome', 'opengemeenten'],
						catalogues: buildMenuItemIconCatalogues(),
						allowCustomSvg: true,
						order: 5,
					},
				},
			}
		},
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
	methods: {
		onAdd() {
			objectStore.clearActiveObject('menu')
			navigationStore.setModal('viewMenu')
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		onRowClick(row) {
			const id = resolveObjectId(row)
			if (id) {
				this.$router.push({ name: 'MenuDetail', params: { id: String(id) } })
				return
			}
			// eslint-disable-next-line no-console
			console.warn('[opencatalogi] onRowClick: no id resolvable from row', row)
		},
		editMenu(menu) {
			const id = menu?.['@self']?.id || menu?.id
			if (id) {
				this.$router.push({ name: 'MenuDetail', params: { id } })
			}
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
