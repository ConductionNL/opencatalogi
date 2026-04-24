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
		:schema="organizationSchema"
		:add-label="t('opencatalogi', 'Add organization')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No organizations found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@create="onSaveOrganization"
		@edit="onSaveOrganization"
		@refresh="handleRefresh"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<!-- Form fields for create/edit dialog -->
		<template #form-fields="{ formData, errors, updateField }">
			<div class="formContainer">
				<NcTextField
					:label="t('opencatalogi', 'Name') + ' *'"
					:value="formData.name || ''"
					:error="!!errors.name"
					:helper-text="errors.name"
					@update:value="v => updateField('name', v)" />
				<NcTextField
					:label="t('opencatalogi', 'Website') + ' *'"
					:value="formData.website || ''"
					:error="!!errors.website"
					:helper-text="errors.website"
					@update:value="v => updateField('website', v)" />
				<NcTextField
					:label="t('opencatalogi', 'Summary')"
					:value="formData.summary || ''"
					@update:value="v => updateField('summary', v)" />
				<NcTextArea
					:label="t('opencatalogi', 'Description')"
					:value="formData.description || ''"
					resize="vertical"
					@update:value="v => updateField('description', v)" />
				<NcTextField
					:label="t('opencatalogi', 'OIN')"
					:value="formData.oin || ''"
					@update:value="v => updateField('oin', v)" />
				<NcTextField
					:label="t('opencatalogi', 'TOOI')"
					:value="formData.tooi || ''"
					@update:value="v => updateField('tooi', v)" />
				<NcTextField
					:label="t('opencatalogi', 'RSIN')"
					:value="formData.rsin || ''"
					@update:value="v => updateField('rsin', v)" />
				<NcTextField
					:label="t('opencatalogi', 'PKI')"
					:value="formData.pki || ''"
					@update:value="v => updateField('pki', v)" />
				<NcTextField
					:label="t('opencatalogi', 'Image (url)')"
					:value="formData.image || ''"
					@update:value="v => updateField('image', v)" />
			</div>
		</template>

		<!-- Mass actions -->
		<template #action-items>
			<NcActionButton close-after-click :disabled="selectedIds.length === 0" @click="onMassDelete">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete selected') }}
			</NcActionButton>
			<NcActionButton close-after-click :disabled="selectedIds.length === 0" @click="onMassPublish">
				<template #icon>
					<PublishIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Publish selected') }}
			</NcActionButton>
			<NcActionButton close-after-click :disabled="selectedIds.length === 0" @click="onMassDepublish">
				<template #icon>
					<PublishOffIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Depublish selected') }}
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
				<NcActionButton close-after-click @click="$refs.indexPage.openFormDialog(row)">
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
import { NcActions, NcActionButton, NcTextField, NcTextArea } from '@nextcloud/vue'
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
		NcTextField,
		NcTextArea,
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
		return { selectedIds: [], viewMode: 'table', isRefreshing: false }
	},
	computed: {
		organizationSchema() {
			return {
				title: t('opencatalogi', 'Organization'),
				properties: {
					name: { type: 'string', title: t('opencatalogi', 'Name'), required: true, minLength: 1 },
					website: { type: 'string', title: t('opencatalogi', 'Website'), required: true },
					summary: { type: 'string', title: t('opencatalogi', 'Summary') },
					description: { type: 'string', title: t('opencatalogi', 'Description') },
					oin: { type: 'string', title: t('opencatalogi', 'OIN') },
					tooi: { type: 'string', title: t('opencatalogi', 'TOOI') },
					rsin: { type: 'string', title: t('opencatalogi', 'RSIN') },
					pki: { type: 'string', title: t('opencatalogi', 'PKI') },
					image: { type: 'string', title: t('opencatalogi', 'Image') },
				},
				required: ['name', 'website'],
			}
		},
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
			const c = objectStore.getCollection('organization')
			return Array.isArray(c) ? c : c?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('organization') || { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	mounted() { objectStore.fetchCollection('organization') },
	methods: {
		onAdd() { objectStore.clearActiveObject('organization'); this.$refs.indexPage.openFormDialog(null) },
		async onSaveOrganization(formData) {
			try {
				if (formData.id) { await objectStore.updateObject('organization', formData.id, formData) } else { await objectStore.createObject('organization', formData) }
				this.$refs.indexPage.setFormResult({ success: true })
				await objectStore.fetchCollection('organization')
			} catch (error) { this.$refs.indexPage.setFormResult({ error: error.message || 'Failed to save organization' }) }
		},
		async handleRefresh() { this.isRefreshing = true; try { await objectStore.fetchCollection('organization') } finally { this.isRefreshing = false } },
		onPageChange(page) { objectStore.fetchCollection('organization', { _page: page }) },
		onPageSizeChange(size) { objectStore.fetchCollection('organization', { _page: 1, _limit: size }) },
		onSelect(ids) { this.selectedIds = ids; objectStore.setSelectedObjects(ids) },
		onRowClick(row) { objectStore.setActiveObject('organization', row); navigationStore.setModal('viewOrganization') },
		viewOrganization(org) { objectStore.setActiveObject('organization', org); navigationStore.setModal('viewOrganization') },
		copyOrganization(org) { objectStore.setActiveObject('organization', org); navigationStore.setDialog('copyObject', { objectType: 'organization', dialogTitle: 'Organization' }) },
		deleteOrganization(org) { objectStore.setActiveObject('organization', org); navigationStore.setDialog('deleteObject', { objectType: 'organization', dialogTitle: 'Organization' }) },
		onMassDelete() { navigationStore.setDialog('massDeleteObjects', { objectType: 'organization', dialogTitle: 'Organizations' }) },
		onMassPublish() { navigationStore.setDialog('massPublishObjects', { objectType: 'organization', dialogTitle: 'Organizations' }) },
		onMassDepublish() { navigationStore.setDialog('massDepublishObjects', { objectType: 'organization', dialogTitle: 'Organizations' }) },
	},
}
</script>

<style scoped>
.formContainer > * { margin-block-end: 10px; }
</style>
