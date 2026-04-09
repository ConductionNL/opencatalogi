<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Themes')"
		:description="t('opencatalogi', 'Manage your website themes and visual styling')"
		:show-title="true"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('theme')"
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
		:schema="themeSchema"
		:add-label="t('opencatalogi', 'Add Theme')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No themes found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@create="onSaveTheme"
		@edit="onSaveTheme"
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
					:label="t('opencatalogi', 'Title') + ' *'"
					:value="formData.title || ''"
					:error="!!errors.title"
					:helper-text="errors.title"
					@update:value="v => updateField('title', v)" />
				<NcTextField
					:label="t('opencatalogi', 'Summary')"
					:value="formData.summary || ''"
					@update:value="v => updateField('summary', v)" />
				<NcTextArea
					:label="t('opencatalogi', 'Description')"
					:value="formData.description || ''"
					@update:value="v => updateField('description', v)" />
				<NcTextField
					:label="t('opencatalogi', 'Image (url)')"
					:value="formData.image || ''"
					@update:value="v => updateField('image', v)" />
			</div>
		</template>

		<!-- Row actions -->
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton close-after-click @click="viewTheme(row)">
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
				<NcActionButton close-after-click @click="copyTheme(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton close-after-click @click="deleteTheme(row)">
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

export default {
	name: 'ThemeIndex',
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
	},
	data() {
		return {
			selectedIds: [],
			viewMode: 'cards',
			isRefreshing: false,
		}
	},
	computed: {
		themeSchema() {
			return {
				title: t('opencatalogi', 'Theme'),
				properties: {
					title: { type: 'string', title: t('opencatalogi', 'Title'), required: true, minLength: 1 },
					summary: { type: 'string', title: t('opencatalogi', 'Summary') },
					description: { type: 'string', title: t('opencatalogi', 'Description') },
					image: { type: 'string', title: t('opencatalogi', 'Image') },
				},
				required: ['title'],
			}
		},
		tableColumns() {
			return [
				{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true },
				{ key: 'status', label: t('opencatalogi', 'Status'), sortable: true },
				{ key: 'summary', label: t('opencatalogi', 'Summary') },
			]
		},
		currentObjects() {
			const collection = objectStore.getCollection('theme')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		currentPagination() {
			return objectStore.getPagination('theme')
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	mounted() {
		objectStore.fetchCollection('theme')
	},
	methods: {
		onAdd() {
			objectStore.clearActiveObject('theme')
			this.$refs.indexPage.openFormDialog(null)
		},
		async onSaveTheme(formData) {
			try {
				const isEdit = !!formData.id
				if (isEdit) {
					await objectStore.updateObject('theme', formData.id, formData)
				} else {
					await objectStore.createObject('theme', formData)
				}
				this.$refs.indexPage.setFormResult({ success: true })
				await objectStore.fetchCollection('theme')
			} catch (error) {
				this.$refs.indexPage.setFormResult({ error: error.message || 'Failed to save theme' })
			}
		},
		async handleRefresh() {
			this.isRefreshing = true
			try {
				await objectStore.fetchCollection('theme')
			} finally {
				this.isRefreshing = false
			}
		},
		onPageChange(page) {
			objectStore.fetchCollection('theme', { _page: page })
		},
		onPageSizeChange(size) {
			objectStore.fetchCollection('theme', { _page: 1, _limit: size })
		},
		onSelect(ids) {
			this.selectedIds = ids
		},
		onRowClick(row) {
			objectStore.setActiveObject('theme', row)
			navigationStore.setModal('viewTheme')
		},
		viewTheme(theme) {
			objectStore.setActiveObject('theme', theme)
			navigationStore.setModal('viewTheme')
		},
		copyTheme(theme) {
			objectStore.setActiveObject('theme', theme)
			navigationStore.setDialog('copyObject', { objectType: 'theme', dialogTitle: 'Theme' })
		},
		deleteTheme(theme) {
			objectStore.setActiveObject('theme', theme)
			navigationStore.setDialog('deleteObject', { objectType: 'theme', dialogTitle: 'Theme' })
		},
	},
}
</script>

<style scoped>
.formContainer > * {
	margin-block-end: 10px;
}
</style>
