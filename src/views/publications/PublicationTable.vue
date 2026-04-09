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
		<template #action-items>
			<NcActionButton close-after-click :disabled="selectedPublicationIds.length === 0" @click="bulkDeletePublications"><template #icon><Delete :size="20" /></template>{{ t('opencatalogi', 'Delete Selected') }}</NcActionButton>
			<NcActionButton close-after-click :disabled="selectedPublicationIds.length === 0" @click="bulkPublishPublications"><template #icon><Publish :size="20" /></template>{{ t('opencatalogi', 'Publish Selected') }}</NcActionButton>
			<NcActionButton close-after-click :disabled="selectedPublicationIds.length === 0" @click="bulkDepublishPublications"><template #icon><PublishOff :size="20" /></template>{{ t('opencatalogi', 'Depublish Selected') }}</NcActionButton>
		</template>
		<template #column-name="{ row }"><span class="titleWithIcon"><PublishedIcon :object="row" :size="16" /><span>{{ row['@self']?.name || row.title || row.name || row.id }}</span></span></template>
		<template #column-published="{ row }">{{ row['@self']?.published ? formatDate(row['@self'].published) : t('opencatalogi', 'No') }}</template>
		<template #column-files="{ row }"><NcCounterBubble :count="getFilesCount(row)" /></template>
		<template #column-updated="{ row }">{{ row['@self']?.updated ? formatDate(row['@self'].updated) : 'N/A' }}</template>
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon><DotsHorizontal :size="20" /></template>
				<NcActionButton close-after-click @click="viewPublication(row)"><template #icon><Pencil :size="20" /></template>{{ t('opencatalogi', 'Edit') }}</NcActionButton>
				<NcActionButton close-after-click @click="copyPublication(row)"><template #icon><ContentCopy :size="20" /></template>{{ t('opencatalogi', 'Copy') }}</NcActionButton>
				<NcActionButton v-if="shouldShowPublishAction(row)" close-after-click @click="singlePublishPublication(row)"><template #icon><Publish :size="20" /></template>{{ t('opencatalogi', 'Publish') }}</NcActionButton>
				<NcActionButton v-if="shouldShowDepublishAction(row)" close-after-click @click="singleDepublishPublication(row)"><template #icon><PublishOff :size="20" /></template>{{ t('opencatalogi', 'Depublish') }}</NcActionButton>
				<NcActionButton close-after-click @click="addAttachment(row)"><template #icon><FilePlusOutline :size="20" /></template>{{ t('opencatalogi', 'Add Attachment') }}</NcActionButton>
				<NcActionButton close-after-click @click="singleDeletePublication(row)"><template #icon><TrashCanOutline :size="20" /></template>{{ t('opencatalogi', 'Delete') }}</NcActionButton>
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
	components: { CnIndexPage, NcActions, NcActionButton, NcCounterBubble, DotsHorizontal, Pencil, ContentCopy, TrashCanOutline, Delete, Publish, PublishOff, FilePlusOutline, PublishedIcon },
	data() { return { viewMode: 'table', isRefreshing: false } },
	computed: {
		tableColumns() { return [{ key: 'name', label: t('opencatalogi', 'Name'), sortable: true }, { key: 'published', label: t('opencatalogi', 'Published'), sortable: true }, { key: 'files', label: t('opencatalogi', 'Files') }, { key: 'updated', label: t('opencatalogi', 'Updated'), sortable: true }] },
		filteredPublications() { return objectStore.getCollection('publication')?.results || [] },
		currentPagination() { return catalogStore.publicationPagination || { page: 1, limit: 20, total: 0, pages: 0 } },
		selectedPublicationIds() { return (objectStore.selectedObjects || []).map(obj => obj.id || obj['@self']?.id).filter(Boolean) },
	},
	mounted() { catalogStore.fetchPublications({}, this.$route.params.catalogSlug) },
	methods: {
		getFilesCount(pub) { const c = pub?.['@self']?.filesCount || pub?.['@self']?.attachmentsCount || pub?.['@self']?.attachmentCount; if (typeof c === 'number') return c; const f = pub?.['@self']?.files; if (Array.isArray(f)) return f.length; if (f) return 1; return 0 },
		formatDate(dateString) { if (!dateString) return 'N/A'; if (!getValidISOstring(dateString)) return dateString; return new Date(dateString).toLocaleString() },
		onSelect(ids) { const selected = this.filteredPublications.filter(pub => ids.includes(pub['@self']?.id || pub.id)).map(pub => ({ ...pub, id: pub['@self']?.id || pub.id })); objectStore.setSelectedObjects(selected) },
		onPageChanged(page) { catalogStore.fetchPublications({ page, limit: this.currentPagination.limit || 20 }) },
		onPageSizeChanged(size) { catalogStore.fetchPublications({ page: 1, limit: size }) },
		addPublication() { objectStore.setActiveObject('publication', null); navigationStore.setModal('viewObject') },
		async refreshPublications() { this.isRefreshing = true; try { await catalogStore.fetchPublications(); objectStore.setSelectedObjects([]) } finally { this.isRefreshing = false } },
		viewPublication(pub) { objectStore.setActiveObject('publication', pub); navigationStore.setModal('viewObject') },
		copyPublication(pub) { objectStore.setActiveObject('publication', pub); navigationStore.setDialog('copyPublication') },
		addAttachment(pub) { objectStore.setActiveObject('publication', pub); navigationStore.setModal('AddAttachment') },
		singleDeletePublication(pub) { objectStore.setSelectedObjects([{ ...pub, id: pub['@self']?.id || pub.id }]); navigationStore.setDialog('massDeleteObject') },
		singlePublishPublication(pub) { objectStore.setSelectedObjects([{ ...pub, id: pub['@self']?.id || pub.id }]); navigationStore.setDialog('massPublishObjects') },
		singleDepublishPublication(pub) { objectStore.setSelectedObjects([{ ...pub, id: pub['@self']?.id || pub.id }]); navigationStore.setDialog('massDepublishObjects') },
		bulkDeletePublications() { if (this.selectedPublicationIds.length) navigationStore.setDialog('massDeleteObject') },
		bulkPublishPublications() { if (this.selectedPublicationIds.length) navigationStore.setDialog('massPublishObjects') },
		bulkDepublishPublications() { if (this.selectedPublicationIds.length) navigationStore.setDialog('massDepublishObjects') },
		shouldShowPublishAction(pub) { return !pub['@self']?.published || pub['@self']?.depublished },
		shouldShowDepublishAction(pub) { return pub['@self']?.published && !pub['@self']?.depublished },
		getValidISOstring,
	},
}
</script>

<style scoped>
.titleWithIcon { display: flex; align-items: center; gap: 8px; }
</style>
