<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Publications')"
		:description="t('opencatalogi', 'Manage your publications and their status')"
		:show-title="true"
		:objects="filteredPublications"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="catalogStore.isLoading"
		:selectable="true"
		:selected-ids="selectedPublicationIds"
		:show-view-toggle="true"
		:show-view-action="false"
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
		:actions="rowActions"
		@add="addPublication"
		@refresh="refreshPublications"
		@page-changed="onPageChanged"
		@page-size-changed="onPageSizeChanged"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="toggleSelection">
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

		<!-- Card view: custom publication card -->
		<template #card="{ object, selected }">
			<PublicationCard
				:object="object"
				:selected="selected"
				:selectable="true"
				@click="toggleSelection(object)"
				@select="toggleSelection(object)">
				<template #actions="{ object: pub }">
					<CnRowActions :actions="rowActions" :row="pub" />
				</template>
			</PublicationCard>
		</template>

		<!-- Custom column: name with published icon -->
		<template #column-name="{ row }">
			<span class="titleWithIcon">
				<PublishedIcon :object="row" :size="16" />
				<span>{{ row['@self']?.name || row.title || row.name || row.id }}</span>
			</span>
		</template>

		<!-- Custom column: status -->
		<template #column-published="{ row }">
			<template v-if="getPublicationStatus(row) === 'concept'">
				<span v-if="row.publicatiedatum">{{ t('opencatalogi', 'Scheduled for') }} {{ formatDate(row.publicatiedatum) }}</span>
				<span v-else>{{ t('opencatalogi', 'Concept') }}</span>
			</template>
			<template v-else-if="getPublicationStatus(row) === 'published'">
				{{ t('opencatalogi', 'Published on') }} {{ formatDate(row.publicatiedatum) }}
			</template>
			<template v-else>
				{{ t('opencatalogi', 'Depublished on') }} {{ formatDate(row.depublicatiedatum) }}
			</template>
		</template>

		<!-- Custom column: files count -->
		<template #column-files="{ row }">
			<NcCounterBubble :count="getFilesCount(row)" />
		</template>

		<!-- Custom column: updated date -->
		<template #column-updated="{ row }">
			{{ row['@self']?.updated ? formatDate(row['@self'].updated) : 'N/A' }}
		</template>
	</CnIndexPage>
</template>

<script>
import { NcActionButton, NcCounterBubble } from '@nextcloud/vue'
import { CnIndexPage, CnRowActions } from '@conduction/nextcloud-vue'
import getValidISOstring from '../../services/getValidISOstring.js'
import { isPublished, getPublicationStatus } from '../../services/publicationStatus.js'
import { schemaHasPublicationDateFields } from '../../services/schemaHelpers.js'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import FilePlusOutline from 'vue-material-design-icons/FilePlusOutline.vue'
import PublishedIcon from '../../components/PublishedIcon.vue'
import PublicationCard from '../../components/PublicationCard.vue'

export default {
	name: 'PublicationTable',
	components: {
		CnIndexPage,
		CnRowActions,
		NcActionButton,
		NcCounterBubble,
		Delete,
		Publish,
		PublishOff,
		PublishedIcon,
		PublicationCard,
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
				{ key: 'published', label: t('opencatalogi', 'Status'), sortable: true },
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
		rowActions() {
			return [
				{
					label: t('opencatalogi', 'Edit'),
					icon: Pencil,
					handler: (row) => this.viewPublication(row),
				},
				{
					label: t('opencatalogi', 'Copy'),
					icon: ContentCopy,
					handler: (row) => this.copyPublication(row),
				},
				{
					label: t('opencatalogi', 'Publish'),
					icon: Publish,
					handler: (row) => this.singlePublishPublication(row),
					visible: (row) => !isPublished(row),
					disabled: (row) => !schemaHasPublicationDateFields(row),
					title: (row) => schemaHasPublicationDateFields(row)
						? undefined
						: t('opencatalogi', 'This schema does not support publishing. Ask your IT manager for help.'),
				},
				{
					label: t('opencatalogi', 'Depublish'),
					icon: PublishOff,
					handler: (row) => this.singleDepublishPublication(row),
					visible: (row) => isPublished(row),
					disabled: (row) => !schemaHasPublicationDateFields(row),
					title: (row) => schemaHasPublicationDateFields(row)
						? undefined
						: t('opencatalogi', 'This schema does not support depublishing. Ask your IT manager for help.'),
				},
				{
					label: t('opencatalogi', 'Add Attachment'),
					icon: FilePlusOutline,
					handler: (row) => this.addAttachment(row),
				},
				{
					label: t('opencatalogi', 'Delete'),
					icon: TrashCanOutline,
					handler: (row) => this.singleDeletePublication(row),
					destructive: true,
				},
			]
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
			catalogStore.fetchPublications({ page, limit: this.currentPagination.limit || 20 }, this.$route.params.catalogSlug)
		},
		onPageSizeChanged(pageSize) {
			catalogStore.fetchPublications({ page: 1, limit: pageSize }, this.$route.params.catalogSlug)
		},
		addPublication() {
			objectStore.setActiveObject('publication', null)
			navigationStore.setModal('viewObject')
		},
		async refreshPublications() {
			this.isRefreshing = true
			try {
				await catalogStore.fetchPublications({}, this.$route.params.catalogSlug)
				objectStore.setSelectedObjects([])
			} finally {
				this.isRefreshing = false
			}
		},
		viewPublication(publication) {
			objectStore.setActiveObject('publication', publication)
			navigationStore.setModal('viewObject')
		},
		copyPublication(publication) {
			objectStore.setActiveObject('publication', publication)
			navigationStore.setDialog('copyObject', { objectType: 'publication', dialogTitle: 'Publication' })
		},
		addAttachment(publication) {
			objectStore.setActiveObject('publication', publication)
			navigationStore.setModal('addAttachment')
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
		getValidISOstring,
		getPublicationStatus,
		toggleSelection(object) {
			const id = object['@self']?.id || object.id
			const currentIds = [...this.selectedPublicationIds]
			const newIds = currentIds.includes(id)
				? currentIds.filter(i => i !== id)
				: [...currentIds, id]
			this.onSelect(newIds)
		},
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
