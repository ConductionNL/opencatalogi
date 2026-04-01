<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Organizations')"
		:description="t('opencatalogi', 'Manage your organizations and their configurations')"
		:show-title="true"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('organization')"
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
		:add-label="t('opencatalogi', 'Add Organization')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No organizations found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@refresh="handleRefresh"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<!-- Mass actions in action bar -->
		<template #action-items>
			<NcActionButton close-after-click
				:disabled="selectedIds.length === 0"
				@click="onMassDelete">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete Selected') }}
			</NcActionButton>
			<NcActionButton close-after-click
				:disabled="selectedIds.length === 0"
				@click="onMassPublish">
				<template #icon>
					<PublishIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Publish Selected') }}
			</NcActionButton>
			<NcActionButton close-after-click
				:disabled="selectedIds.length === 0"
				@click="onMassDepublish">
				<template #icon>
					<PublishOffIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Depublish Selected') }}
			</NcActionButton>
		</template>

		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="viewOrganization(row)">
					<template #icon>
						<Eye :size="20" />
					</template>
					{{ t('opencatalogi', 'View') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="editOrganization(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="copyOrganization(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="deleteOrganization(row)">
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
import Delete from 'vue-material-design-icons/Delete.vue'
import PublishIcon from 'vue-material-design-icons/Publish.vue'
import PublishOffIcon from 'vue-material-design-icons/PublishOff.vue'

export default {
	name: 'OrganizationIndex',
	components: {
		CnIndexPage,
		NcActions,
		NcActionButton,
		DotsHorizontal,
		Eye,
		Pencil,
		ContentCopy,
		TrashCanOutline,
		Delete,
		PublishIcon,
		PublishOffIcon,
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
				{ key: 'name', label: t('opencatalogi', 'Name'), sortable: true },
				{ key: 'website', label: t('opencatalogi', 'Website'), sortable: true },
				{ key: 'summary', label: t('opencatalogi', 'Summary') },
				{ key: 'oin', label: t('opencatalogi', 'OIN'), sortable: true },
				{ key: 'tooi', label: t('opencatalogi', 'TOOI'), sortable: true },
				{ key: 'rsin', label: t('opencatalogi', 'RSIN'), sortable: true },
			]
		},
		currentObjects() {
			const collection = objectStore.getCollection('organization')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('organization')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	mounted() {
		objectStore.fetchCollection('organization')
	},
	methods: {
		onAdd() {
			objectStore.clearActiveObject('organization')
			navigationStore.setModal('organization')
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await objectStore.fetchCollection('organization')
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChange(page) {
			objectStore.fetchCollection('organization', { _page: page })
		},
		onPageSizeChange(size) {
			objectStore.fetchCollection('organization', { _page: 1, _limit: size })
		},
		onSelect(ids) {
			this.selectedIds = ids
			objectStore.setSelectedObjects(ids)
		},
		onRowClick(row) {
			objectStore.setActiveObject('organization', row)
			navigationStore.setModal('viewOrganization')
		},
		viewOrganization(organization) {
			objectStore.setActiveObject('organization', organization)
			navigationStore.setModal('viewOrganization')
		},
		editOrganization(organization) {
			objectStore.setActiveObject('organization', organization)
			navigationStore.setModal('organization')
		},
		copyOrganization(organization) {
			objectStore.setActiveObject('organization', organization)
			navigationStore.setDialog('copyObject', {
				objectType: 'organization',
				dialogTitle: 'Organization',
			})
		},
		deleteOrganization(organization) {
			objectStore.setActiveObject('organization', organization)
			navigationStore.setDialog('deleteObject', {
				objectType: 'organization',
				dialogTitle: 'Organization',
			})
		},
		onMassDelete() {
			navigationStore.setDialog('massDeleteObjects', {
				objectType: 'organization',
				dialogTitle: 'Organizations',
			})
		},
		onMassPublish() {
			navigationStore.setDialog('massPublishObjects', {
				objectType: 'organization',
				dialogTitle: 'Organizations',
			})
		},
		onMassDepublish() {
			navigationStore.setDialog('massDepublishObjects', {
				objectType: 'organization',
				dialogTitle: 'Organizations',
			})
		},
	},
}
</script>
