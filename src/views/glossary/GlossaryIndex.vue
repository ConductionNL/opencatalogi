<!--
	UNREACHABLE COMPONENT — no visual baseline is possible.

	Nothing imports this file: `src/registry.js` is the only place page
	components are handed to CnAppRoot, and it does not list it. `/glossary` is a
	manifest `type: "index"` page rendered by nc-vue's generic CnIndexPage from
	`src/manifest.json`, so this view is superseded migration debris. Confirmed
	by grepping the built bundle: its `name: 'GlossaryIndex'` option occurs 0
	times in `js/opencatalogi-main.js`, while the six wired views each occur once
	— webpack tree-shakes it out entirely.

	See src/views/directory/DirectoryIndex.vue for the full rationale.

	@visual exclude Unreachable: imported by nothing, in no route, tree-shaken out of the shipped bundle; superseded by the manifest type:"index" Glossary page (CnIndexPage). Tracked in ConductionNL/opencatalogi#849.
-->
<template>
	<CnIndexPage
		ref="indexPage"
		:title="t('opencatalogi', 'Glossary')"
		:description="
			t('opencatalogi', 'Manage your glossary terms and definitions')
		"
		:showTitle="true"
		:objects="currentObjects"
		:columns="tableColumns"
		:pagination="currentPagination"
		:loading="objectStore.isLoading('glossary')"
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
		:schema="glossarySchema"
		:addLabel="t('opencatalogi', 'Add term')"
		:showAdd="isAdmin"
		rowKey="id"
		:emptyText="t('opencatalogi', 'No glossary terms found')"
		:refreshing="isRefreshing"
		@add="onAdd"
		@create="onSaveTerm"
		@edit="onSaveTerm"
		@refresh="handleRefresh"
		@pageChanged="onPageChange"
		@pageSizeChanged="onPageSizeChange"
		@viewModeChange="viewMode = $event"
		@select="onSelect"
		@rowClick="onRowClick">
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
		<template #form-fields="{ formData, errors, updateField }">
			<div class="formContainer">
				<NcTextField
					:label="t('opencatalogi', 'Title') + ' *'"
					:modelValue="formData.title || ''"
					:error="!!errors.title"
					:helperText="errors.title"
					maxlength="255"
					@update:modelValue="(v) => updateField('title', v)" />
				<NcTextField
					:label="t('opencatalogi', 'Summary')"
					:modelValue="formData.summary || ''"
					maxlength="255"
					@update:modelValue="(v) => updateField('summary', v)" />
				<NcTextArea
					:label="t('opencatalogi', 'Description')"
					:modelValue="formData.description || ''"
					@update:modelValue="(v) => updateField('description', v)" />
				<NcTextField
					:label="t('opencatalogi', 'External link')"
					:modelValue="formData.externalLink || ''"
					@update:modelValue="(v) => updateField('externalLink', v)" />
				<NcSelect
					:modelValue="formData.keywords || []"
					:inputLabel="t('opencatalogi', 'Keywords')"
					:multiple="true"
					:taggable="true"
					:placeholder="
						t('opencatalogi', 'Type and press Enter to add keywords')
					"
					@update:modelValue="(v) => updateField('keywords', v)" />
			</div>
		</template>
		<template #column-published="{ row }">
			<CnStatusBadge
				:label="
					row.published
						? t('opencatalogi', 'Public')
						: t('opencatalogi', 'Private')
				"
				:colorMap="statusColorMap" />
		</template>
		<template #column-keywords="{ row }">
			{{ row.keywords?.length ? row.keywords.join(', ') : '-' }}
		</template>
		<template #row-actions="{ row }">
			<NcActions>
				<template #icon>
					<DotsHorizontal :size="20" />
				</template>
				<NcActionButton closeAfterClick @click="viewTerm(row)">
					<template #icon>
						<Eye :size="20" />
					</template>
					{{ t('opencatalogi', 'View') }}
				</NcActionButton>
				<NcActionButton
					v-if="isAdmin"
					closeAfterClick
					@click="$refs.indexPage.openFormDialog(row)">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('opencatalogi', 'Edit') }}
				</NcActionButton>
				<NcActionButton
					v-if="isAdmin"
					closeAfterClick
					@click="copyTerm(row)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					{{ t('opencatalogi', 'Copy') }}
				</NcActionButton>
				<NcActionButton
					v-if="isAdmin"
					closeAfterClick
					@click="deleteTerm(row)">
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
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import {
	NcActionButton,
	NcActions,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { useIsAdmin } from '../../composables/useIsAdmin.js'
import { navigationStore, objectStore } from '../../store/store.js'

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
		NcNoteCard,
		DotsHorizontal,
		Eye,
		Pencil,
		ContentCopy,
		TrashCanOutline,
	},

	setup() {
		const { isAdmin, loaded } = useIsAdmin()
		return { isAdmin, loaded, objectStore, navigationStore }
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
					title: {
						type: 'string',
						title: t('opencatalogi', 'Title'),
						required: true,
						minLength: 1,
					},

					summary: { type: 'string', title: t('opencatalogi', 'Summary') },
					description: {
						type: 'string',
						title: t('opencatalogi', 'Description'),
					},

					externalLink: {
						type: 'string',
						title: t('opencatalogi', 'External link'),
					},

					keywords: {
						type: 'array',
						title: t('opencatalogi', 'Keywords'),
					},
				},

				required: ['title'],
			}
		},

		tableColumns() {
			return [
				{ key: 'title', label: t('opencatalogi', 'Title'), sortable: true },
				{
					key: 'published',
					label: t('opencatalogi', 'Status'),
					sortable: true,
				},
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
			return (
				objectStore.getPagination('glossary') || {
					total: 0,
					page: 1,
					pages: 1,
					limit: 20,
				}
			)
		},
	},

	mounted() {
		objectStore.fetchCollection('glossary')
	},

	methods: {
		t,
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
				this.$refs.indexPage.setFormResult({
					error: error.message || 'Failed to save term',
				})
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
			objectStore.fetchCollection('glossary', {
				_page: page,
				_limit: this.currentPagination.limit || 20,
			})
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
			navigationStore.setDialog('copyObject', {
				objectType: 'glossary',
				dialogTitle: 'Term',
			})
		},

		deleteTerm(term) {
			objectStore.setActiveObject('glossary', term)
			navigationStore.setDialog('deleteObject', {
				objectType: 'glossary',
				dialogTitle: 'Term',
			})
		},
	},
}
</script>

<style scoped>
.formContainer > * {
	margin-block-end: 10px;
}
</style>
