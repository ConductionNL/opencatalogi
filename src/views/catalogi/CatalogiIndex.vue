<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Catalogs')"
		:description="
			t('opencatalogi', 'Manage your data catalogs and their configurations')
		"
		:showTitle="true"
		:schema="schema"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('catalog')"
		:selectable="true"
		:selectedIds="selectedIds"
		:showViewToggle="true"
		:actions="rowActions"
		:showViewAction="false"
		:showEditAction="false"
		:showCopyAction="false"
		:showDeleteAction="false"
		:showMassImport="false"
		:showMassExport="false"
		:showMassCopy="false"
		:showMassDelete="false"
		:viewMode="viewMode"
		:sortKey="sortKey"
		:sortOrder="sortOrder"
		:includeColumns="visibleColumns"
		:addLabel="t('opencatalogi', 'Add Catalog')"
		:showAdd="isAdmin"
		rowKey="id"
		:emptyText="t('opencatalogi', 'No catalogs found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="handleRefresh"
		@sort="onSort"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick"
		@view="viewCatalog">
		<template #below-header>
			<NcNoteCard v-if="loaded && !isAdmin" type="info">
				{{
					t(
						'opencatalogi',
						'This page is read-only. Only administrators can create, edit, or delete entries here.',
					)
				}}
			</NcNoteCard>
		</template>

		<!-- Custom column: visibility badge -->
		<template #column-listed="{ row }">
			<CnStatusBadge
				:label="
					row.listed
						? t('opencatalogi', 'Public')
						: t('opencatalogi', 'Private')
				"
				:colorMap="visibilityColorMap" />
		</template>

		<!-- Custom column: registers count -->
		<template #column-registers="{ row }">
			{{ row.registers?.length || 0 }}
		</template>

		<!-- Custom column: schemas count -->
		<template #column-schemas="{ row }">
			{{ row.schemas?.length || 0 }}
		</template>

		<!-- Custom column: organization name -->
		<template #column-organization="{ row }">
			{{ row.organization ? getOrganizationName(row.organization) : '-' }}
		</template>
	</CnIndexPage>
</template>

<script>
import { inject } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useListView, CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import { objectStore, navigationStore } from '../../store/store.js'
import { NcNoteCard } from '@nextcloud/vue'
import { useIsAdmin } from '../../composables/useIsAdmin.js'
import { resolveObjectId } from '../../services/resolveObjectId.js'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'CatalogiIndex',
	components: {
		CnIndexPage,
		CnStatusBadge,
		NcNoteCard,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const {
			schema,
			sortKey,
			sortOrder,
			visibleColumns,
			onSort,
			onPageChange,
			onPageSizeChange,
			refresh,
		} = useListView('catalog', {
			sidebarState,
			objectStore,
		})
		const { isAdmin, loaded } = useIsAdmin()
		return {
			schema,
			sortKey,
			sortOrder,
			visibleColumns,
			onSort,
			onPageChange,
			onPageSizeChange,
			refresh,
			objectStore,
			isAdmin,
			loaded,
		}
	},
	data() {
		return {
			selectedIds: [],
			viewMode: 'table',
			isRefreshing: false,
			visibilityColorMap: {
				[t('opencatalogi', 'Public')]: 'success',
				[t('opencatalogi', 'Private')]: 'default',
			},
		}
	},
	computed: {
		/**
		 * Row actions as data rather than a #row-actions slot. CnIndexPage feeds
		 * this same array to both CnRowActions and CnContextMenu, so the row menu
		 * and the right-click menu stay 1:1 — slot markup cannot be replayed into
		 * the context menu, which is how the two drifted apart.
		 *
		 * @return {Array<object>} Action definitions for CnIndexPage.
		 * @spec openspec/specs/retrofit-2026-05-26-object-table-listing/spec.md#requirement-table-actions-and-pagination-req-tbl-003
		 */
		rowActions() {
			return [
				{
					label: t('opencatalogi', 'View'),
					icon: Eye,
					handler: this.viewCatalog,
				},
				{
					label: t('opencatalogi', 'Edit'),
					icon: Pencil,
					visible: () => this.isAdmin,
					handler: this.editCatalog,
				},
				{
					label: t('opencatalogi', 'View Catalog'),
					icon: OpenInApp,
					handler: this.openCatalog,
				},
				{
					label: t('opencatalogi', 'Copy'),
					icon: ContentCopy,
					visible: () => this.isAdmin,
					handler: this.copyCatalog,
				},
				{
					label: t('opencatalogi', 'Delete'),
					icon: TrashCanOutline,
					destructive: true,
					visible: () => this.isAdmin,
					handler: this.deleteCatalog,
				},
			]
		},

		tableColumns() {
			return [
				{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true },
				{
					key: 'listed',
					label: t('opencatalogi', 'Status'),
					sortable: true,
				},
				{ key: 'registers', label: t('opencatalogi', 'Registers') },
				{ key: 'schemas', label: t('opencatalogi', 'Schemas') },
				{ key: 'organization', label: t('opencatalogi', 'Organization') },
			]
		},
		currentObjects() {
			// useListView expects collections[type] to be an array;
			// OpenCatalogi's store wraps it in { results: [] }
			const collection = objectStore.getCollection('catalog')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return (
				objectStore.getPagination('catalog') || {
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
				}
			)
		},
	},
	methods: {
		/**
		 * Refresh, driving the spinner via `isRefreshing`. CnIndexPage only
		 * animates the button itself in self-fetch mode; a page that owns its
		 * own useListView has to bind `:refreshing` and toggle it here.
		 *
		 * @return {Promise<void>}
		 */
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await this.refresh()
			} finally {
				this.isRefreshing = false
			}
		},
		onAdd() {
			objectStore.clearActiveObject('catalog')
			navigationStore.setModal('catalog')
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		/**
		 * Open the clicked row's catalog detail page by route id.
		 *
		 * @param {object} row The clicked table row.
		 * @return {void}
		 * @spec openspec/specs/catalogs/spec.md#requirement-view-catalog-details-and-detail-page-cat-015
		 */
		onRowClick(row) {
			const id = resolveObjectId(row)
			if (id) {
				this.$router.push({
					name: 'CatalogDetail',
					params: { id: String(id) },
				})
				return
			}
			// No id resolvable — log the row so misshapen payloads surface
			// in the browser console instead of silently doing nothing.
			// eslint-disable-next-line no-console
			console.warn('[opencatalogi] onRowClick: no id resolvable from row', row)
		},
		/**
		 * Open a catalog's detail page from the row action menu.
		 *
		 * @param {object} catalog The catalog row payload.
		 * @return {void}
		 * @spec openspec/specs/catalogs/spec.md#requirement-view-catalog-details-and-detail-page-cat-015
		 */
		viewCatalog(catalog) {
			const id = resolveObjectId(catalog)
			if (id) {
				this.$router.push({
					name: 'CatalogDetail',
					params: { id: String(id) },
				})
				return
			}
			// eslint-disable-next-line no-console
			console.warn(
				'[opencatalogi] viewCatalog: no id resolvable from row',
				catalog,
			)
		},
		editCatalog(catalog) {
			objectStore.setActiveObject('catalog', catalog)
			navigationStore.setModal('catalog')
		},
		openCatalog(catalog) {
			this.$router.push(`/publications/${catalog?.slug}`)
		},
		copyCatalog(catalog) {
			objectStore.setActiveObject('catalog', catalog)
			navigationStore.setDialog('copyObject', {
				objectType: 'catalog',
				dialogTitle: 'Catalogus',
			})
		},
		deleteCatalog(catalog) {
			objectStore.setActiveObject('catalog', catalog)
			navigationStore.setDialog('deleteObject', {
				objectType: 'catalog',
				dialogTitle: 'Catalogus',
			})
		},
		getOrganizationName(organizationId) {
			const organization = objectStore.getObject(
				'organization',
				organizationId,
			)
			return organization?.name || 'Unknown Organization'
		},
	},
}
</script>
