<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Glossary')"
		:description="t('opencatalogi', 'Manage your glossary terms and definitions')"
		:show-title="true"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('glossary')"
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
		:add-label="t('opencatalogi', 'Add Term')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No glossary terms found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="handleRefresh"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<!-- Custom column: status -->
		<template #column-published="{ row }">
			<CnStatusBadge
				:label="row.published ? t('opencatalogi', 'Public') : t('opencatalogi', 'Private')"
				:color-map="statusColorMap" />
		</template>

		<!-- Custom column: keywords -->
		<template #column-keywords="{ row }">
			{{ row.keywords?.length ? row.keywords.join(', ') : '-' }}
		</template>

		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="viewTerm(row)">
					<template #icon>
						<Eye :size="20" />
					</template>
					{{ t('opencatalogi', 'View') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="editTerm(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="copyTerm(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="deleteTerm(row)">
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
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'GlossaryIndex',
	components: {
		CnIndexPage,
		CnStatusBadge,
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
			viewMode: 'cards',
			isRefreshing: false,
			statusColorMap: {
				[t('opencatalogi', 'Public')]: 'success',
				[t('opencatalogi', 'Private')]: 'default',
			},
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true },
				{ key: 'published', label: t('opencatalogi', 'Status'), sortable: true },
				{ key: 'relatedTerms', label: t('opencatalogi', 'Related Terms') },
				{ key: 'keywords', label: t('opencatalogi', 'Keywords') },
			]
		},
		currentObjects() {
			const collection = objectStore.getCollection('glossary')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('glossary')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	mounted() {
		objectStore.fetchCollection('glossary')
	},
	methods: {
		onAdd() {
			objectStore.clearActiveObject('glossary')
			navigationStore.setModal('glossary')
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await objectStore.fetchCollection('glossary')
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChange(page) {
			objectStore.fetchCollection('glossary', { _page: page })
		},
		onPageSizeChange(size) {
			objectStore.fetchCollection('glossary', { _page: 1, _limit: size })
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		onRowClick(row) {
			objectStore.setActiveObject('glossary', row)
			navigationStore.setModal('viewGlossary')
		},
		viewTerm(term) {
			objectStore.setActiveObject('glossary', term)
			navigationStore.setModal('viewGlossary')
		},
		editTerm(term) {
			objectStore.setActiveObject('glossary', term)
			navigationStore.setModal('glossary')
		},
		copyTerm(term) {
			objectStore.setActiveObject('glossary', term)
			navigationStore.setDialog('copyObject', { objectType: 'glossary', dialogTitle: 'Term' })
		},
		deleteTerm(term) {
			objectStore.setActiveObject('glossary', term)
			navigationStore.setDialog('deleteObject', { objectType: 'glossary', dialogTitle: 'Term' })
		},
	},
}
</script>
