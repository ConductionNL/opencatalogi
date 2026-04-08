<script setup>
import { inject } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useListView } from '@conduction/nextcloud-vue'
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'

const sidebarState = inject('sidebarState', null)
const { schema, sortKey, sortOrder, visibleColumns, onSort, refresh } = useListView('publication', {
	sidebarState,
	objectStore,
})
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Publications')"
		:description="t('opencatalogi', 'Manage your publications and their status')"
		:show-title="true"
		:schema="schema"
		:objects="filteredPublications"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="catalogStore.isLoading"
		:selectable="true"
		:selected-ids="selectedPublicationIds"
		:show-view-toggle="true"
		:show-edit-action="false"
		:show-copy-action="false"
		:show-delete-action="false"
		:show-mass-import="false"
		:show-mass-export="false"
		:show-mass-copy="false"
		:show-mass-delete="false"
		:view-mode="viewMode"
		:add-label="t('opencatalogi', 'Add Publication')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No publications found')"
		:refreshing="isRefreshing"
		@add="addPublication"
		@refresh="refreshPublications"
		@page-changed="onPageChanged"
		@page-size-changed="onPageSizeChanged"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="viewPublication">
		<!-- Mass actions -->
		<template #action-items>
			<NcActionButton close-after-click
				:disabled="selectedPublicationIds.length === 0"
				@click="bulkDeletePublications">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete Selected') }}
			</NcActionButton>
			<NcActionButton close-after-click
				:disabled="selectedPublicationIds.length === 0"
				@click="bulkPublishPublications">
				<template #icon>
					<Publish :size="20" />
				</template>
				{{ t('opencatalogi', 'Publish Selected') }}
			</NcActionButton>
			<NcActionButton close-after-click
				:disabled="selectedPublicationIds.length === 0"
				@click="bulkDepublishPublications">
				<template #icon>
					<PublishOff :size="20" />
				</template>
				{{ t('opencatalogi', 'Depublish Selected') }}
			</NcActionButton>
		</template>

		<!-- Custom column: name with published icon -->
		<template #column-name="{ row }">
			<span class="titleWithIcon">
				<PublishedIcon :object="row" :size="16" />
				<span>{{ row['@self']?.name || row.title || row.name || row.id }}</span>
			</span>
		</template>

		<!-- Custom column: published date -->
		<template #column-published="{ row }">
			{{ row['@self']?.published ? formatDate(row['@self'].published) : t('opencatalogi', 'No') }}
		</template>

		<!-- Custom column: files count -->
		<template #column-files="{ row }">
			<NcCounterBubble :count="getFilesCount(row)" />
		</template>

		<!-- Custom column: updated date -->
		<template #column-updated="{ row }">
			{{ row['@self']?.updated ? formatDate(row['@self'].updated) : 'N/A' }}
		</template>

		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="viewPublication(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="copyPublication(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton
					v-if="shouldShowPublishAction(row)"
					close-after-click
					@click="singlePublishPublication(row)">
					<template #icon>
						<Publish :size="20" />
					</template>
					{{ t('opencatalogi', 'Publish') }}
				</NcActionButton>
				<NcActionButton
					v-if="shouldShowDepublishAction(row)"
					close-after-click
					@click="singleDepublishPublication(row)">
					<template #icon>
						<PublishOff :size="20" />
					</template>
					{{ t('opencatalogi', 'Depublish') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="addAttachment(row)">
					<template #icon>
						<FilePlusOutline :size="20" />
					</template>
					{{ t('opencatalogi', 'Add Attachment') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="singleDeletePublication(row)">
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
import { NcActions, NcActionButton, NcCounterBubble } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import getValidISOstring from '../../services/getValidISOstring.js'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import FilePlusOutline from 'vue-material-design-icons/FilePlusOutline.vue'
import PublishedIcon from '../../components/PublishedIcon.vue'

export default {
	name: 'PublicationTable',
	components: {
		CnIndexPage,
		NcActions,
		NcActionButton,
		NcCounterBubble,
		DotsHorizontal,
		Pencil,
		ContentCopy,
		TrashCanOutline,
		Delete,
		Publish,
		PublishOff,
		FilePlusOutline,
		PublishedIcon,
	},
	data() {
		return {
			viewMode: 'table',
			isRefreshing: false,
		}
	},
	computed: {
		tableColumns() {
			return [
				{ key: 'name', label: t('opencatalogi', 'Name'), sortable: true },
				{ key: 'published', label: t('opencatalogi', 'Published'), sortable: true },
				{ key: 'files', label: t('opencatalogi', 'Files') },
				{ key: 'updated', label: t('opencatalogi', 'Updated'), sortable: true },
			]
		},
		filteredPublications() {
			return objectStore.getCollection('publication')?.results || []
		},
		currentPagination() {
			return catalogStore.publicationPagination || { page: 1, limit: 20, total: 0, pages: 0 }
		},
		selectedPublicationIds() {
			return (objectStore.selectedObjects || []).map(obj =>
				obj.id || obj['@self']?.id,
			).filter(Boolean)
		},
	},
	mounted() {
		catalogStore.fetchPublications({}, this.$route.params.catalogSlug)
	},
	methods: {
		getFilesCount(publication) {
			const countFromSelf = publication?.['@self']?.filesCount || publication?.['@self']?.attachmentsCount || publication?.['@self']?.attachmentCount
			if (typeof countFromSelf === 'number') return countFromSelf
			const filesProp = publication?.['@self']?.files
			if (Array.isArray(filesProp)) return filesProp.length
			if (filesProp) return 1
			return 0
		},
		formatDate(dateString) {
			if (!dateString) return 'N/A'
			if (!getValidISOstring(dateString)) return dateString
			return new Date(dateString).toLocaleString()
		},
		onSelect(ids) {
			// Map IDs back to full objects for the store
			const selectedObjects = this.filteredPublications
				.filter(pub => ids.includes(pub['@self']?.id || pub.id))
				.map(pub => ({ ...pub, id: pub['@self']?.id || pub.id }))
			objectStore.setSelectedObjects(selectedObjects)
		},
		onPageChanged(page) {
			catalogStore.fetchPublications({ page, limit: this.currentPagination.limit || 20 })
		},
		onPageSizeChanged(pageSize) {
			catalogStore.fetchPublications({ page: 1, limit: pageSize })
		},
		addPublication() {
			objectStore.setActiveObject('publication', null)
			navigationStore.setModal('viewObject')
		},
		async refreshPublications() {
			this.isRefreshing = true
			try {
				await catalogStore.fetchPublications()
				objectStore.setSelectedObjects([])
			} finally {
				this.isRefreshing = false
			}
		},
		viewPublication(publication) {
			const id = publication?.['@self']?.id || publication?.id
			if (id) {
				this.$router.push({
					name: 'PublicationDetail',
					params: { catalogSlug: this.$route.params.catalogSlug, id },
				})
			}
		},
		copyPublication(publication) {
			objectStore.setActiveObject('publication', publication)
			navigationStore.setDialog('copyPublication')
		},
		addAttachment(publication) {
			objectStore.setActiveObject('publication', publication)
			navigationStore.setModal('AddAttachment')
		},
		singleDeletePublication(publication) {
			const publicationObject = { ...publication, id: publication['@self']?.id || publication.id }
			objectStore.setSelectedObjects([publicationObject])
			navigationStore.setDialog('massDeleteObject')
		},
		singlePublishPublication(publication) {
			const publicationObject = { ...publication, id: publication['@self']?.id || publication.id }
			objectStore.setSelectedObjects([publicationObject])
			navigationStore.setDialog('massPublishObjects')
		},
		singleDepublishPublication(publication) {
			const publicationObject = { ...publication, id: publication['@self']?.id || publication.id }
			objectStore.setSelectedObjects([publicationObject])
			navigationStore.setDialog('massDepublishObjects')
		},
		bulkDeletePublications() {
			if (this.selectedPublicationIds.length === 0) return
			navigationStore.setDialog('massDeleteObject')
		},
		bulkPublishPublications() {
			if (this.selectedPublicationIds.length === 0) return
			navigationStore.setDialog('massPublishObjects')
		},
		bulkDepublishPublications() {
			if (this.selectedPublicationIds.length === 0) return
			navigationStore.setDialog('massDepublishObjects')
		},
		shouldShowPublishAction(publication) {
			return !publication['@self']?.published || publication['@self']?.depublished
		},
		shouldShowDepublishAction(publication) {
			return publication['@self']?.published && !publication['@self']?.depublished
		},
		getValidISOstring,
	},
}
</script>

<style scoped>
.titleWithIcon {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
