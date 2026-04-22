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
		:schema="glossarySchema"
		:add-label="t('opencatalogi', 'Add term')"
		row-key="id"
		:empty-text="t('opencatalogi', 'No glossary terms found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@create="onSaveTerm"
		@edit="onSaveTerm"
		@refresh="handleRefresh"
		@page-changed="onPageChange"
		@page-size-changed="onPageSizeChange"
		@view-mode-change="viewMode = $event"
		@select="onSelect"
		@row-click="onRowClick">
		<template #form-fields="{ formData, errors, updateField }">
			<div class="formContainer">
				<NcTextField
					:label="t('opencatalogi', 'Title') + ' *'"
					:value="formData.title || ''"
					:error="!!errors.title"
					:helper-text="errors.title"
					maxlength="255"
					@update:value="v => updateField('title', v)" />
				<NcTextField
					:label="t('opencatalogi', 'Summary')"
					:value="formData.summary || ''"
					maxlength="255"
					@update:value="v => updateField('summary', v)" />
				<NcTextArea
					:label="t('opencatalogi', 'Description')"
					:value="formData.description || ''"
					@update:value="v => updateField('description', v)" />
				<NcTextField
					:label="t('opencatalogi', 'External link')"
					:value="formData.externalLink || ''"
					@update:value="v => updateField('externalLink', v)" />
				<NcSelect
					:value="formData.keywords || []"
					:input-label="t('opencatalogi', 'Keywords')"
					:multiple="true"
					:taggable="true"
					:placeholder="t('opencatalogi', 'Type and press Enter to add keywords')"
					@input="v => updateField('keywords', v)" />
			</div>
		</template>
		<template #column-published="{ row }">
			<CnStatusBadge
				:label="row.published ? t('opencatalogi', 'Public') : t('opencatalogi', 'Private')"
				:color-map="statusColorMap" />
		</template>
		<template #column-keywords="{ row }">
			{{ row.keywords?.length ? row.keywords.join(', ') : '-' }}
		</template>
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
				<NcActionButton close-after-click @click="$refs.indexPage.openFormDialog(row)">
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
import { NcActions, NcActionButton, NcTextField, NcTextArea, NcSelect } from '@nextcloud/vue'
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
		NcTextField,
		NcTextArea,
		NcSelect,
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
		glossarySchema() {
			return {
				title: t('opencatalogi', 'Term'),
				properties: {
					title: { type: 'string', title: t('opencatalogi', 'Title'), required: true, minLength: 1 },
					summary: { type: 'string', title: t('opencatalogi', 'Summary') },
					description: { type: 'string', title: t('opencatalogi', 'Description') },
					externalLink: { type: 'string', title: t('opencatalogi', 'External link') },
					keywords: { type: 'array', title: t('opencatalogi', 'Keywords') },
				},
				required: ['title'],
			}
		},
		tableColumns() {
			return [
				{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true },
				{ key: 'published', label: t('opencatalogi', 'Status'), sortable: true },
				{ key: 'relatedTerms', label: t('opencatalogi', 'Related terms') },
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
			this.$refs.indexPage.openFormDialog(null)
		},
		async onSaveTerm(formData) {
			try {
				if (formData.id) {
					await objectStore.updateObject('glossary', formData.id, formData)
				} else {
					await objectStore.createObject('glossary', formData)
				}
				this.$refs.indexPage.setFormResult({ success: true })
				await objectStore.fetchCollection('glossary')
			} catch (error) {
				this.$refs.indexPage.setFormResult({ error: error.message || 'Failed to save term' })
			}
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

<style scoped>
.formContainer > * {
	margin-block-end: 10px;
}
</style>
