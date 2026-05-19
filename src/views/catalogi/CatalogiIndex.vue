<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Catalogs')"
		:description="t('opencatalogi', 'Manage your data catalogs and their configurations')"
		:show-title="true"
		:schema="schema"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('catalog')"
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
		:add-label="t('opencatalogi', 'Add Catalog')"
		:show-add="isAdmin"
		row-key="id"
		:empty-text="t('opencatalogi', 'No catalogs found')"
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

		<!-- Custom column: visibility badge -->
		<template #column-listed="{ row }">
			<CnStatusBadge
				:label="row.listed ? t('opencatalogi', 'Public') : t('opencatalogi', 'Private')"
				:color-map="visibilityColorMap" />
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
				<NcActionButton close-after-click @click="viewCatalog(row)">
					<template #icon>
						<Eye :size="20" />
					</template>
					{{ t('opencatalogi', 'View') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="editCatalog(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="openCatalog(row)">
					<template #icon>
						<OpenInApp :size="20" />
					</template>
					{{ t('opencatalogi', 'View Catalog') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="copyCatalog(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton v-if="isAdmin" close-after-click @click="deleteCatalog(row)">
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
import { useListView, CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import { objectStore, navigationStore } from '../../store/store.js'
import { NcActions, NcActionButton, NcNoteCard } from '@nextcloud/vue'
import { useIsAdmin } from '../../composables/useIsAdmin.js'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
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
		const { schema, sortKey, sortOrder, visibleColumns, onSort, onPageChange, onPageSizeChange, refresh } = useListView('catalog', {
			sidebarState,
			objectStore,
		})
		const { isAdmin, loaded } = useIsAdmin()
		return { schema, sortKey, sortOrder, visibleColumns, onSort, onPageChange, onPageSizeChange, refresh, objectStore, isAdmin, loaded }
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
				{ key: 'listed', label: t('opencatalogi', 'Status'), sortable: true },
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
			return objectStore.getPagination('catalog')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
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
		onRowClick(row) {
			const id = row?.['@self']?.id || row?.id
			if (id) {
				this.$router.push({ name: 'CatalogDetail', params: { id } })
			}
		},
		viewCatalog(catalog) {
			const id = catalog?.['@self']?.id || catalog?.id
			if (id) {
				this.$router.push({ name: 'CatalogDetail', params: { id } })
			}
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
			navigationStore.setDialog('copyObject', { objectType: 'catalog', dialogTitle: 'Catalogus' })
		},
		deleteCatalog(catalog) {
			objectStore.setActiveObject('catalog', catalog)
			navigationStore.setDialog('deleteObject', { objectType: 'catalog', dialogTitle: 'Catalogus' })
		},
		getOrganizationName(organizationId) {
			const organization = objectStore.getObject('organization', organizationId)
			return organization?.name || 'Unknown Organization'
		},
	},
}
</script>
