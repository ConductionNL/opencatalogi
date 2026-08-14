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
		@refresh="refresh"
		@sort="onSort"
		@pageChanged="onPageChange"
		@pageSizeChanged="onPageSizeChange"
		@viewModeChange="viewMode = $event"
		@select="onSelect"
		@rowClick="onRowClick"
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

		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton closeAfterClick @click="viewCatalog(row)">
					<template #icon>
						<Eye :size="20" />
					</template>
					{{ t('opencatalogi', 'View') }}
				</NcActionButton>
				<NcActionButton
					v-if="isAdmin"
					closeAfterClick
					@click="editCatalog(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton closeAfterClick @click="openCatalog(row)">
					<template #icon>
						<OpenInApp :size="20" />
					</template>
					{{ t('opencatalogi', 'View Catalog') }}
				</NcActionButton>
				<NcActionButton
					v-if="isAdmin"
					closeAfterClick
					@click="copyCatalog(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton
					v-if="isAdmin"
					closeAfterClick
					@click="deleteCatalog(row)">
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
import { CnIndexPage, CnStatusBadge, useListView } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions, NcNoteCard } from '@nextcloud/vue'
import { inject } from 'vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import OpenInApp from 'vue-material-design-icons/OpenInApp.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { useIsAdmin } from '../../composables/useIsAdmin.js'
import { resolveObjectId } from '../../services/resolveObjectId.js'
import { navigationStore, objectStore } from '../../store/store.js'

export default {
	name: 'CatalogiIndex',
	components: {
		CnIndexPage,
		CnStatusBadge,
		NcActions,
		NcActionButton,
		NcNoteCard,
		DotsHorizontal,
		Eye,
		Pencil,
		OpenInApp,
		ContentCopy,
		TrashCanOutline,
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
