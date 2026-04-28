<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'viewObject'"
		:name="getModalTitle()"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<template #name>
			<div class="dialog__name">
				<PublishedIcon v-if="shouldShowPublishedIcon"
					:object="currentObject"
					:size="30"
					class="status-icon draft-icon" />
				<Pencil v-else
					:size="30"
					class="status-icon draft-icon" />
				<span>{{ getModalTitle() }}</span>
			</div>
		</template>
		<div class="formContainer viewObjectDialog">
			<!-- Display Object -->
			<div v-if="objectStore.getActiveObject('publication') || isNewObject">
				<!-- For new objects, show catalog/register/schema selection first -->
				<div v-if="isNewObject && !hasSelectedSchema" class="selectionContainer">
					<NcEmptyContent
						v-if="catalogOptions.length === 0"
						name="No catalogs available"
						description="You need at least one catalog before you can create a publication. Create a catalog from the catalogs page first.">
						<template #icon>
							<FolderOutline :size="64" />
						</template>
					</NcEmptyContent>
					<div v-if="catalogOptions.length > 1" class="selectionStep">
						<h3>Select Catalog</h3>
						<p>Choose the catalog where this publication will be stored.</p>
						<NcSelect
							v-model="selectedCatalog"
							:options="catalogOptions"
							input-label="Catalog"
							placeholder="Select a catalog..."
							:disabled="catalogStore.isLoading" />
					</div>

					<div v-if="selectedCatalog && registerOptions.length > 1" class="selectionStep">
						<h3>Select Register</h3>
						<p>Choose the register that will store this publication.</p>
						<NcSelect
							v-model="selectedRegister"
							:options="registerOptions"
							input-label="Register"
							placeholder="Select a register..."
							:disabled="catalogStore.isLoading" />
					</div>

					<div v-if="selectedRegister && schemaOptions.length > 1" class="selectionStep">
						<h3>Select Schema</h3>
						<p>Choose the schema that defines the structure of this publication.</p>
						<NcSelect
							v-model="selectedSchema"
							:options="schemaOptions"
							input-label="Schema"
							placeholder="Select a schema..."
							:disabled="catalogStore.isLoading" />
					</div>

					<div v-if="hasSelectedSchema && !allSelectionsComplete" class="selectionStep">
						<NcButton type="primary" @click="proceedToProperties">
							<template #icon>
								<ArrowRight :size="20" />
							</template>
							Continue to Properties
						</NcButton>
					</div>
				</div>

				<!-- For new objects with schema selected, show properties table -->
				<PropertiesPanel
					v-else-if="isNewObject && (hasSelectedSchema || allSelectionsComplete)"
					v-bind="propertiesPanelBindings"
					@update:selected-property="selectedProperty = $event"
					@update:show-constant-properties="showConstantProperties = $event"
					@update:property-value="onPropertyValueUpdate"
					@drop-property="dropProperty"
					@editor-load="onEditorLoad"
					@editor-blur="onEditorBlur" />

				<!-- For existing objects, show tabs -->
				<div v-else class="tabContainer">
					<BTabs v-model="activeTab" content-class="mt-3" justified>
						<BTab title="Properties" active>
							<PropertiesPanel
								v-bind="propertiesPanelBindings"
								@update:selected-property="selectedProperty = $event"
								@update:show-constant-properties="showConstantProperties = $event"
								@update:property-value="onPropertyValueUpdate"
								@drop-property="dropProperty"
								@editor-load="onEditorLoad"
								@editor-blur="onEditorBlur" />
						</BTab>
						<BTab title="Metadata">
							<CnMetadataTab
								:item="currentObject"
								:replace-rows="true"
								:extra-rows="metadataExtraRows" />
						</BTab>
						<BTab>
							<template #title>
								<div class="tab-title">
									<span>Files</span>
									<NcLoadingIcon v-if="currentObject && objectStore.isLoading(`publication_${currentObject.id}_files`)" :size="16" />
									<NcCounterBubble v-else :count="filesTotalItems" />
								</div>
							</template>
							<!-- Info box for new objects -->
							<NcNoteCard v-if="isNewObject" type="info" class="files-info-card">
								<p><strong>Files can be added after the publication is created.</strong></p>
								<p>Save the publication first, then you'll be able to upload and manage files.</p>
							</NcNoteCard>

							<NcEmptyContent v-if="currentObject && objectStore.isLoading(`publication_${currentObject.id}_files`)"
								title="Loading files..."
								:description="'Loading files for this publication...'">
								<template #icon>
									<NcLoadingIcon :size="64" />
								</template>
							</NcEmptyContent>
							<template v-else-if="paginatedFiles.length > 0">
								<div class="multi-actions-container">
									<NcActions
										:force-name="true"
										:disabled="selectedAttachments.length === 0"
										:title="selectedAttachments.length === 0 ? 'Select one or more files to use mass actions' : `Mass actions (${selectedAttachments.length} selected)`"
										:menu-name="`Mass Actions (${selectedAttachments.length})`">
										<template #icon>
											<FormatListChecks :size="20" />
										</template>
										<NcActionButton
											:disabled="publishLoading.length > 0 || publishableCount === 0"
											close-after-click
											@click="publishSelectedFiles">
											<template #icon>
												<NcLoadingIcon v-if="publishLoading.length > 0" :size="20" />
												<FileOutline v-else :size="20" />
											</template>
											Publish {{ publishableCount }} attachment{{ publishableCount === 1 ? '' : 's' }}
										</NcActionButton>
										<NcActionButton
											:disabled="depublishLoading.length > 0 || depublishableCount === 0"
											close-after-click
											@click="depublishSelectedFiles">
											<template #icon>
												<NcLoadingIcon v-if="depublishLoading.length > 0" :size="20" />
												<LockOutline v-else :size="20" />
											</template>
											Depublish {{ depublishableCount }} attachment{{ depublishableCount === 1 ? '' : 's' }}
										</NcActionButton>
										<NcActionButton
											:disabled="fileIdsLoading.length > 0 || selectedAttachments.length === 0"
											close-after-click
											@click="deleteSelectedFiles">
											<template #icon>
												<NcLoadingIcon v-if="fileIdsLoading.length > 0" :size="20" />
												<Delete v-else :size="20" />
											</template>
											Delete {{ selectedAttachments.length }} attachment{{ selectedAttachments.length === 1 ? '' : 's' }}
										</NcActionButton>
									</NcActions>
								</div>
								<div class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnCheckbox">
													<NcCheckboxRadioSwitch
														:checked="allFilesSelected"
														:indeterminate="someFilesSelected"
														@update:checked="toggleSelectAllFiles" />
												</th>
												<th class="tableColumnExpanded table-row-title">
													Name
												</th>
												<th class="tableColumnConstrained short-column">
													Size
												</th>
												<th class="tableColumnConstrained table-row-type">
													Type
												</th>
												<th :class="`tableColumnConstrained ${editingTags ? 'table-row-labels' : 'short-column'}`">
													Labels
												</th>
												<th class="table-row-actions" />
											</tr>
										</thead>
										<tbody>
											<tr v-for="(attachment, i) in paginatedFiles"
												:key="`${attachment.id}${i}`"
												:class="{ 'active': activeAttachment === attachment.id }"
												class="viewTableRow"
												@click="() => {
													if (activeAttachment === attachment.id) activeAttachment = null
													else activeAttachment = attachment.id
												}">
												<td class="tableColumnCheckbox">
													<NcCheckboxRadioSwitch
														:checked="objectStore.selectedAttachments.includes(attachment.id)"
														@update:checked="(checked) => toggleFileSelection(attachment.id, checked)" />
												</td>
												<td class="tableColumnExpanded table-row-title">
													<div class="file-name-container">
														<div class="file-status-icons">
															<!-- Show warning icon if file is not shared -->
															<ExclamationThick v-if="!attachment.accessUrl && !attachment.downloadUrl"
																v-tooltip="'Not shared'"
																class="warningIcon"
																:size="20" />
															<!-- Show published icon if file is shared -->
															<FileOutline v-else class="publishedIcon" :size="20" />
														</div>
														<span class="file-name">{{ attachment.name ?? attachment?.title }}</span>
													</div>
												</td>
												<td class="tableColumnConstrained short-column">
													{{ formatFileSize(attachment?.size) }}
												</td>
												<td class="tableColumnConstrained table-row-type">
													{{ attachment?.type || 'No type' }}
												</td>
												<td class="tableColumnConstrained td-labels">
													<div class="fileLabelsContainer">
														<span v-if="editingTags !== attachment.id"
															class="files-list__row-action--inline files-list__row-action-system-tags">
															<ul v-if="attachment.labels && attachment.labels.length > 0" class="files-list__system-tags" aria-label="Assigned collaborative tags">
																<li v-for="label of attachment.labels"
																	:key="label"
																	class="files-list__system-tag"
																	:title="label">
																	{{ label }}
																</li>
															</ul>
															<span v-if="!attachment.labels || attachment.labels.length === 0">
																No labels
															</span>
														</span>
														<div v-if="editingTags === attachment.id" class="label-edit-container">
															<NcSelect
																v-model="editedTags"
																:disabled="tagsLoading"
																:loading="tagsLoading"
																:taggable="true"
																:multiple="true"
																:aria-label-combobox="labelOptionsEdit.inputLabel"
																:options="labelOptionsEdit.options"
																@tag="addNewTag" />
															<NcButton
																v-tooltip="'Save labels'"
																type="primary"
																size="small"
																:aria-label="`save labels for ${attachment.name ?? attachment?.title ?? 'file'}`"
																class="editTagsButton"
																@click="saveTags(attachment, editedTags)">
																<template #icon>
																	<ContentSaveOutline :size="20" />
																</template>
															</NcButton>
															<NcButton
																v-tooltip="'Cancel'"
																type="secondary"
																size="small"
																@click="cancelFileLabelEditing">
																<template #icon>
																	<Cancel :size="20" />
																</template>
															</NcButton>
														</div>
													</div>
												</td>
												<td class="table-row-actions">
													<NcActions
														v-if="editingTags !== attachment.id"
														:aria-label="`Actions for ${attachment.name ?? attachment?.title ?? 'file'}`">
														<NcActionButton @click="openFile(attachment)">
															<template #icon>
																<OpenInNew :size="20" />
															</template>
															View
														</NcActionButton>
														<NcActionButton
															:disabled="editingTags && editingTags !== attachment.id || tagsLoading"
															@click="editFileLabels(attachment)">
															<template #icon>
																<Tag :size="20" />
															</template>
															Edit Labels
														</NcActionButton>
														<NcActionButton
															v-if="!attachment.accessUrl && !attachment.downloadUrl"
															:disabled="publishLoading.includes(attachment.id)"
															@click="publishFile(attachment)">
															<template #icon>
																<NcLoadingIcon v-if="publishLoading.includes(attachment.id)" :size="20" />
																<FileOutline v-else :size="20" />
															</template>
															Publish
														</NcActionButton>
														<NcActionButton
															v-else
															:disabled="depublishLoading.includes(attachment.id)"
															@click="depublishFile(attachment)">
															<template #icon>
																<NcLoadingIcon v-if="depublishLoading.includes(attachment.id)" :size="20" />
																<LockOutline v-else :size="20" />
															</template>
															Depublish
														</NcActionButton>
														<NcActionButton
															:disabled="fileIdsLoading.includes(attachment.id)"
															@click="deleteFile(attachment)">
															<template #icon>
																<NcLoadingIcon v-if="fileIdsLoading.includes(attachment.id)" :size="20" />
																<Delete v-else :size="20" />
															</template>
															Delete
														</NcActionButton>
													</NcActions>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</template>
							<NcEmptyContent v-else-if="!isNewObject"
								name="No files attached"
								description="No files have been attached to this object">
								<template #icon>
									<FileOutline :size="64" />
								</template>
							</NcEmptyContent>

							<!-- Files Pagination -->
							<PaginationComponent
								v-if="filesTotalItems > 10"
								:current-page="objectStore.getPagination('publication_files').page"
								:total-pages="filesTotalPages"
								:total-items="filesTotalItems"
								:current-page-size="filesCurrentPageSize"
								:page-size-options="pageSizeOptions"
								:min-items-to-show="5"
								@page-changed="onFilesPageChanged"
								@page-size-changed="onFilesPageSizeChanged" />
						</BTab>
					</BTabs>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				Close
			</NcButton>
			<NcButton v-if="!isNewObject" @click="uploadFiles">
				<template #icon>
					<Upload :size="20" />
				</template>
				Add File
			</NcButton>
			<NcButton v-if="shouldShowPublishAction(currentObject)"
				@click="singlePublishObject">
				<template #icon>
					<Publish :size="20" />
				</template>
				Publish
			</NcButton>
			<NcButton v-if="shouldShowDepublishAction(currentObject)"
				@click="singleDepublishObject">
				<template #icon>
					<PublishOff :size="20" />
				</template>
				Depublish
			</NcButton>
			<NcButton v-if="!isNewObject"
				type="error"
				@click="singleDeleteObject">
				<template #icon>
					<Delete :size="20" />
				</template>
				Delete
			</NcButton>
			<NcButton type="primary" :disabled="isSaving" @click="saveObject">
				<template #icon>
					<NcLoadingIcon v-if="isSaving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ isSaving ? (isNewObject ? 'Creating...' : 'Saving...') : (isNewObject ? 'Create' : 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcActions,
	NcActionButton,
	NcNoteCard,
	NcCounterBubble,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcEmptyContent,
	NcSelect,
} from '@nextcloud/vue'
import { CnMetadataTab } from '@conduction/nextcloud-vue'
import { BTabs, BTab } from 'bootstrap-vue'
import '@toast-ui/editor/dist/toastui-editor.css'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'
import PublishedIcon from '../../components/PublishedIcon.vue'
import PropertiesPanel from '../../components/PropertiesPanel.vue'

export default {
	name: 'ViewObject',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcCounterBubble,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcEmptyContent,
		NcSelect,
		CnMetadataTab,
		BTabs,
		BTab,
		Cancel,
		FileOutline,
		FolderOutline,
		OpenInNew,
		Delete,
		Upload,
		ContentSave,
		ContentSaveOutline,
		LockOutline,
		Tag,
		FormatListChecks,
		Publish,
		PublishOff,
		Pencil,
		ExclamationThick,
		ArrowRight,
		PaginationComponent,
		PublishedIcon,
		PropertiesPanel,
	},
	data() {
		return {
			activeTab: 0,
			formData: {}, // Ensure this is always an object, never an array
			selectedProperty: null,
			isSaving: false,

			// Markdown editors instances
			markdownEditors: {},

			// Files tab properties
			activeAttachment: null,
			publishLoading: [],
			depublishLoading: [],
			fileIdsLoading: [],
			filesCurrentPage: 1,
			filesPerPage: 500,
			// Page size options matching PaginationComponent
			pageSizeOptions: [
				{ value: 10, label: '10' },
				{ value: 20, label: '20' },
				{ value: 50, label: '50' },
				{ value: 100, label: '100' },
				{ value: 250, label: '250' },
				{ value: 500, label: '500' },
				{ value: 1000, label: '1000' },
			],
			// Selection flow properties
			selectedCatalog: null,
			selectedRegister: null,
			selectedSchema: null,
			showProperties: false,

			// Constant/immutable properties visibility
			showConstantProperties: false,

			// Label editing properties (from UploadFiles.vue)
			editingTags: null,
			editedTags: [],
			labelOptionsEdit: {
				inputLabel: 'Labels',
				multiple: true,
				options: [],
			},
			tagsLoading: false,
		}
	},
	computed: {
		currentObject() {
			return objectStore.getActiveObject('publication')
		},

		isNewObject() {
			const obj = objectStore.getActiveObject('publication')
			return !obj || !obj?.['@self']?.id
		},
		/**
		 * Full JSON schema selected for the current/new object. Sourced from objectStore.availableSchemas.
		 * Returns null when no schema is resolvable yet.
		 */
		resolvedSchema() {
			if (this.isNewObject && this.selectedSchema) {
				return objectStore.availableSchemas.find(schema => schema.id === this.selectedSchema.id) || null
			}
			if (this.currentObject && this.currentObject['@self']?.schema) {
				const schemaRef = this.currentObject['@self'].schema
				const schemaId = typeof schemaRef === 'object' ? (schemaRef.id || schemaRef.uuid) : schemaRef
				if (schemaId) {
					return objectStore.availableSchemas.find(schema => schema.id === schemaId) || null
				}
			}
			return null
		},
		/**
		 * Per-property cell-config overrides forwarded into CnPropertiesTab → CnPropertyValueCell.
		 * Only properties that need runtime-driven options (e.g. `themes` from objectStore) need entries here.
		 */
		propertyOverrides() {
			return {
				themes: {
					widget: 'select',
					selectOptions: this.themeOptions,
					selectMultiple: true,
				},
			}
		},
		/**
		 * Publication-specific metadata rows for CnMetadataTab.
		 * Includes the standard ID/Created/Updated rows plus version/register/schema/locked/published/depublished.
		 */
		metadataExtraRows() {
			if (!this.currentObject) return []
			const obj = this.currentObject

			const register = obj['@self']?.register
			let registerDisplay = 'Not set'
			if (register) {
				if (typeof register === 'object') {
					registerDisplay = register.title || register.name || register.id || register
				} else {
					const availableRegister = objectStore.availableRegisters.find(r => r.id === register)
					registerDisplay = availableRegister?.title || register
				}
			}

			const schema = obj['@self']?.schema
			let schemaDisplay = 'Not set'
			if (schema) {
				if (typeof schema === 'object') {
					schemaDisplay = schema.title || schema.name || schema.id || schema
				} else {
					const availableSchema = objectStore.availableSchemas.find(s => s.id === schema)
					schemaDisplay = availableSchema?.title || schema
				}
			}

			const locked = obj['@self']?.locked
			let lockedDisplay = 'Not locked'
			if (locked) {
				if (typeof locked === 'object') {
					const lockedBy = locked.lockedBy || 'Unknown user'
					const lockedAt = locked.lockedAt ? new Date(locked.lockedAt).toLocaleString() : 'Unknown time'
					const proc = locked.process ? ` (${locked.process})` : ''
					lockedDisplay = `Locked by ${lockedBy} at ${lockedAt}${proc}`
				} else {
					lockedDisplay = 'Locked'
				}
			}

			const fmtDate = (v, fallback) => v ? new Date(v).toLocaleString() : fallback

			return [
				['ID', obj.id || 'Not set'],
				['Version', obj['@self']?.version || 'Not set'],
				['Register', registerDisplay],
				['Schema', schemaDisplay],
				['Locked', lockedDisplay],
				['Created', fmtDate(obj['@self']?.created, 'Not set')],
				['Updated', fmtDate(obj['@self']?.updated, 'Not set')],
				['Published', fmtDate(obj['@self']?.published, 'Not published')],
				['Depublished', fmtDate(obj['@self']?.depublished, 'Not depublished')],
			]
		},
		/**
		 * Whether the resolved schema has any properties marked const/immutable. Used to gate the
		 * "show constant & immutable properties" toggle button.
		 */
		hasConstantOrImmutableProperties() {
			const props = this.resolvedSchema?.properties
			if (!props) return false
			return Object.values(props).some(p => p && (p.const !== undefined || p.immutable === true || p.readOnly === true))
		},
		// Files tab computed properties
		paginatedFiles() {
			const filesData = objectStore.getRelatedData('publication', 'files')
			const files = filesData?.results || []
			// Ensure files is an array
			if (!Array.isArray(files)) {
				console.warn('Files data is not an array:', files)
				return []
			}
			return files
		},
		selectedAttachments() {
			return objectStore.selectedAttachments
		},
		filesTotalPages() {
			const filesPagination = objectStore.getPagination('publication_files')
			return filesPagination.pages
		},
		filesTotalItems() {
			const filesPagination = objectStore.getPagination('publication_files')
			return filesPagination.total
		},
		filesCurrentPageSize() {
			const filesPagination = objectStore.getPagination('publication_files')
			return filesPagination.limit
		},
		allFilesSelected() {
			return this.paginatedFiles.length > 0 && this.paginatedFiles.every(file => objectStore.selectedAttachments.includes(file.id))
		},
		someFilesSelected() {
			return objectStore.selectedAttachments.length > 0 && !this.allFilesSelected
		},
		catalogOptions() {
			return objectStore.getCollection('catalog').results.map(catalog => ({
				id: catalog.id,
				label: catalog.title,
			}))
		},
		registerOptions() {
			if (!this.selectedCatalog) {
				return []
			}

			const fullCatalog = objectStore.getCollection('catalog').results.find(catalog => catalog.id === this.selectedCatalog.id)
			if (!fullCatalog) {
				return []
			}

			const selectedCatalogRegisterIds = fullCatalog.registers || []

			return objectStore.availableRegisters
				.filter(register => selectedCatalogRegisterIds.includes(register.id))
				.map(register => ({
					id: register.id,
					label: register.title,
				}))
		},
		schemaOptions() {
			if (!this.selectedRegister || !this.selectedCatalog) {
				return []
			}

			const register = objectStore.availableRegisters.find(register => register.id === this.selectedRegister.id)
			const catalog = objectStore.getCollection('catalog').results.find(catalog => catalog.id === this.selectedCatalog.id)

			if (!register || !catalog) {
				return []
			}

			const registerSchemaIds = register.schemas?.map(schema => schema.id) || []
			const catalogSchemaIds = catalog.schemas || []

			// only get schema ids where the id is in both registerSchemaIds and catalogSchemaIds
			const validSchemaIds = registerSchemaIds.filter(id => catalogSchemaIds.includes(id))

			return objectStore.availableSchemas
				.filter(schema => validSchemaIds.includes(schema.id))
				.map(schema => ({
					id: schema.id,
					label: schema.title,
				}))
		},
		hasSelectedSchema() {
			return this.selectedSchema !== null && this.showProperties
		},
		allSelectionsComplete() {
			return this.selectedCatalog && this.selectedRegister && this.selectedSchema
		},

		shouldShowPublishedIcon() {
			return this.currentObject && this.currentObject['@self']
		},
		themeOptions() {
			const themes = objectStore.getCollection('theme').results || []
			return themes.map(theme => ({
				id: theme.id,
				label: theme.title || `#${theme.id}`,
			}))
		},
		publishableCount() {
			const selected = objectStore.selectedAttachments || []
			if (selected.length === 0) return 0
			const files = this.paginatedFiles || []
			return files.filter(f => selected.includes(f.id)).filter(f => !f.accessUrl && !f.downloadUrl).length
		},
		depublishableCount() {
			const selected = objectStore.selectedAttachments || []
			if (selected.length === 0) return 0
			const files = this.paginatedFiles || []
			return files.filter(f => selected.includes(f.id)).filter(f => (f.accessUrl || f.downloadUrl)).length
		},
		propertiesPanelBindings() {
			return {
				resolvedSchema: this.resolvedSchema,
				currentObject: this.currentObject,
				formData: this.formData,
				selectedProperty: this.selectedProperty,
				showConstantProperties: this.showConstantProperties,
				hasConstantOrImmutableProperties: this.hasConstantOrImmutableProperties,
				propertyOverrides: this.propertyOverrides,
				isMarkdownProperty: this.isMarkdownProperty,
				getMarkdownEditorOptions: this.getMarkdownEditorOptions,
				canDropProperty: this.canDropProperty,
				getDropPropertyTooltip: this.getDropPropertyTooltip,
			}
		},
	},
	watch: {
		currentObject: {
			handler(newValue) {
				if (newValue) {
					this.initializeData()
				}
			},
			deep: true,
		},
		selectedCatalog: {
			handler(newCatalog) {
				// Auto-select register if there's only one
				if (newCatalog && this.registerOptions.length === 1) {
					this.selectedRegister = this.registerOptions[0]
				} else if (!newCatalog) {
					this.selectedRegister = null
					this.selectedSchema = null
					this.showProperties = false
				}
			},
		},
		selectedRegister: {
			handler(newRegister) {
				// Auto-select schema if there's only one
				if (newRegister && this.schemaOptions.length === 1) {
					this.selectedSchema = this.schemaOptions[0]
				} else if (!newRegister) {
					this.selectedSchema = null
					this.showProperties = false
				}
			},
		},
		selectedSchema: {
			handler(newSchema) {
				if (!newSchema) {
					this.showProperties = false
				} else if (this.allSelectionsComplete) {
					// Automatically show properties when all selections are complete
					this.showProperties = true
				}
			},
		},
	},
	mounted() {
		this.initializeData()
		// Fetch themes for the theme options dropdown
		objectStore.fetchCollection('theme')
		// Fetch tags for the label options dropdown
		this.getAllTags()
		// Listen to tags updates from UploadFiles modal
		EventBus.$on('upload-files:tags-updated', this.onUploadFilesTagsUpdated)
		EventBus.$on('upload-files:closed', this.onUploadFilesClosed)
	},
	destroyed() {
		try {
			EventBus.$off('upload-files:tags-updated', this.onUploadFilesTagsUpdated)
			EventBus.$off('upload-files:closed', this.onUploadFilesClosed)
		} catch (e) {
			// ignore
		}
	},
	methods: {
		onUploadFilesTagsUpdated(payload) {
			try {
				const tags = Array.isArray(payload && payload.tags) ? payload.tags : []
				const newTags = Array.isArray(payload && payload.newTags) ? payload.newTags : []
				console.info('>>> [VIEWOBJECT] RECEIVED TAGS-UPDATED FROM UPLOADFILES', {
					total: tags.length,
					newTags,
				})
				if (!this.labelOptionsEdit) {
					this.labelOptionsEdit = { inputLabel: 'Labels', multiple: true, options: [] }
				}
				this.labelOptionsEdit.options = [...tags]
			} catch (e) {
				console.error('Failed to apply updated tags from UploadFiles', e)
			}
		},
		onUploadFilesClosed(payload) {
			try {
				// prefer payload tags
				const tagsFromPayload = Array.isArray(payload && payload.tags) ? payload.tags : null
				if (tagsFromPayload) {
					if (!this.labelOptionsEdit) {
						this.labelOptionsEdit = { inputLabel: 'Labels', multiple: true, options: [] }
					}
					this.labelOptionsEdit.options = [...tagsFromPayload]
					return
				}
				// fallback: from store or re-fetch
				const stored = objectStore.getCollection('tags')
				if (Array.isArray(stored)) {
					if (!this.labelOptionsEdit) {
						this.labelOptionsEdit = { inputLabel: 'Labels', multiple: true, options: [] }
					}
					this.labelOptionsEdit.options = [...stored]
				} else {
					this.getAllTags()
				}
			} catch (e) {
				console.error('Failed to handle UploadFiles closed', e)
			}
		},
		getModalTitle() {
			// For new objects, show "Create Publication"
			if (this.isNewObject) {
				return 'Create Publication'
			}

			if (!this.currentObject) return 'View Object'

			const name = this.currentObject['@self']?.name
				|| this.currentObject.name
				|| this.currentObject.title
				|| this.currentObject.id
				|| 'Untitled'

			// Try to get schema name from the object itself
			let schemaName = 'Publication'

			// Check if schema is an object with title/name properties
			if (this.currentObject.schema && typeof this.currentObject.schema === 'object') {
				schemaName = this.currentObject.schema.title
					|| this.currentObject.schema.name
					|| this.currentObject.schema.id
					|| 'Publication'
			} else if (this.currentObject['@self']?.schema && typeof this.currentObject['@self'].schema === 'object') {
				// Check if @self.schema is an object with title/name properties
				schemaName = this.currentObject['@self'].schema.title
					|| this.currentObject['@self'].schema.name
					|| this.currentObject['@self'].schema.id
					|| 'Publication'
			} else if (typeof this.currentObject['@self']?.schema === 'string') {
				// If it's a string, use it directly
				schemaName = this.currentObject['@self'].schema
			}

			return `${name} (${schemaName})`
		},

		closeModal() {
			// Clear state first
			this.activeTab = 0
			this.selectedProperty = null

			// Clear Files tab state
			this.activeAttachment = null
			objectStore.selectedAttachments = []
			this.publishLoading = []
			this.depublishLoading = []
			this.fileIdsLoading = []

			// Clear selection flow state
			this.selectedCatalog = null
			this.selectedRegister = null
			this.selectedSchema = null
			this.showProperties = false

			// Clear label editing state
			this.editingTags = null
			this.editedTags = []

			// Close modal
			navigationStore.setModal(null)
		},
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},
		proceedToProperties() {
			this.showProperties = true
		},
		async initializeData() {
			if (!this.currentObject) {
				// For new objects, initialize with empty form data and auto-select if possible
				this.formData = {}

				const catalogs = objectStore.getCollection('catalog').results

				// Check if we have a catalogSlug route param
				const catalogSlug = this.$route.params.catalogSlug
				if (catalogSlug) {
					// Find catalog by slug
					const matchingCatalog = catalogs.find(catalog => catalog.slug === catalogSlug)
					if (matchingCatalog) {
						this.selectedCatalog = {
							id: matchingCatalog.id,
							label: matchingCatalog.title,
						}
					}
				} else if (catalogs.length === 1) {
					// If no catalog found by slug and only one catalog exists, auto-select it
					this.selectedCatalog = {
						id: catalogs[0].id,
						label: catalogs[0].title,
					}
				}

				// Auto-select register and schema if only one option exists.
				// Existing watchers on selectedCatalog/selectedRegister handle
				// the cascading updates, so a single tick is sufficient.
				await this.$nextTick()
				if (this.registerOptions.length === 1) {
					this.selectedRegister = this.registerOptions[0]
					await this.$nextTick()
					if (this.schemaOptions.length === 1) {
						this.selectedSchema = this.schemaOptions[0]
					}
				}

				return
			}

			const initial = this.currentObject
			const filtered = {}
			for (const key in initial) {
				if (key !== '@self' && key !== 'id') {
					// Ensure we have a safe copy of the value
					try {
						filtered[key] = JSON.parse(JSON.stringify(initial[key]))
					} catch (e) {
						// If JSON serialization fails, use the original value
						filtered[key] = initial[key]
					}
				}
			}

			// Ensure formData is always an object, never an array
			this.formData = {}
			// Create a safe copy for formData
			try {
				const parsedData = JSON.parse(JSON.stringify(filtered))
				// Explicitly ensure it's an object
				if (typeof parsedData === 'object' && !Array.isArray(parsedData)) {
					this.formData = parsedData
				} else {
					this.formData = { ...filtered }
				}
			} catch (e) {
				// Fallback if JSON serialization fails
				this.formData = { ...filtered }
			}

			// Ensure themes are properly initialized as an array of IDs
			if (this.formData.themes && Array.isArray(this.formData.themes)) {
				// Convert any theme objects back to IDs if needed
				this.formData.themes = this.formData.themes.map(theme =>
					typeof theme === 'object' ? theme.id : theme,
				)
			}
		},
		async saveObject() {
			this.isSaving = true

			try {
				const isCreating = this.isNewObject

				// For new objects, validate we have the required selections
				if (isCreating && (!this.selectedSchema || !this.selectedRegister || !this.selectedCatalog)) {
					return
				}

				let objectData
				let endpoint
				let method

				if (isCreating) {
					// Create a new object using openregister API
					// For new objects, build complete object with all schema properties
					objectData = this.buildCompleteObjectData()
					endpoint = `/index.php/apps/openregister/api/objects/${this.selectedRegister.id}/${this.selectedSchema.id}`
					method = 'POST'
				} else {
					// Update existing object using openregister API
					// For existing objects, merge current object with all schema properties
					objectData = this.buildCompleteObjectData()
					// Get register and schema info from the current object
					const { registerId, schemaId } = this.getRegisterSchemaIds(this.currentObject)
					const objectId = this.currentObject['@self']?.id || this.currentObject.id
					endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}`
					method = 'PUT'
				}

				const response = await fetch(endpoint, {
					method,
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(objectData),
				})

				if (!response.ok) {
					const errorText = await response.text()
					throw new Error(`Failed to ${isCreating ? 'create' : 'update'} publication: ${response.status} ${response.statusText} - ${errorText}`)
				}

				const result = await response.json()
				const schema = objectStore.availableSchemas.find(schema => schema.id === Number(result['@self'].schema))
				// Set the newly created/updated object as active in the object store
				objectStore.setActiveObject('publication', { ...result, '@self': { ...result['@self'], schema } })

				// Clear form data since we now have the saved object
				this.formData = {}

				// Refresh the publications list
				catalogStore.fetchPublications()

				// Close modal for edit mode, keep open for create mode (which transitions to edit mode)
				if (!isCreating) {
					setTimeout(() => {
						this.closeModal()
					}, 1000)
				}
			} catch (e) {
				console.error('Save error:', e)
			} finally {
				this.isSaving = false
			}
		},
		// Property validation and editing methods
		getPropertyDisplayName(key) {
			// Ensure we always have a valid key
			if (!key || typeof key !== 'string') {
				console.warn('Invalid key passed to getPropertyDisplayName:', key)
				return 'Unknown Property'
			}

			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			// Return the title if it exists and is not empty or just "new" (placeholder)
			const title = schemaProperty?.title
			if (title && typeof title === 'string' && title.trim() !== '' && title.trim().toLowerCase() !== 'new') {
				return title
			}

			return key
		},
		getMarkdownEditorOptions(key) {
			return {
				placeholder: this.getPropertyDisplayName(key),
				minHeight: '200px',
				language: 'en-US',
				hideModeSwitch: true, // Hide the markdown/wysiwyg mode switch
				toolbarItems: [
					['heading', 'bold', 'italic', 'strike'],
					['hr', 'quote'],
					['ul', 'ol', 'task', 'indent', 'outdent'],
					['table', 'image', 'link'],
					['code', 'codeblock'],
				],
				viewer: true, // Enable WYSIWYG mode
				initialEditType: 'wysiwyg', // Start in WYSIWYG mode
				// Hook into the editor events to remove borders after initialization
				hooks: {
					addImageBlobHook: () => false, // Disable image uploads
				},
				events: {
					load: (editor) => {
						// Remove borders after the editor is fully loaded
						this.$nextTick(() => {
							this.removeBordersFromEditor(editor)
						})
					},
					changeMode: (editor) => {
						// Remove borders when mode changes
						this.$nextTick(() => {
							this.removeBordersFromEditor(editor)
						})
					},
				},
			}
		},
		removeBordersFromEditor(editor) {
			try {
				// Get the editor container
				const editorEl = editor.getEl()
				if (editorEl) {
					// Remove borders from all nested elements
					const allElements = editorEl.querySelectorAll('*')
					allElements.forEach(el => {
						el.style.border = 'none'
						el.style.borderWidth = '0'
						el.style.borderStyle = 'none'
						el.style.borderColor = 'transparent'
						el.style.outline = 'none'
						el.style.boxShadow = 'none'
					})

					// Also remove from the container itself
					editorEl.style.border = 'none'
					editorEl.style.borderWidth = '0'
					editorEl.style.borderStyle = 'none'
					editorEl.style.borderColor = 'transparent'
					editorEl.style.outline = 'none'
					editorEl.style.boxShadow = 'none'
				}
			} catch (error) {
				console.warn('Could not remove borders from editor:', error)
			}
		},
		// Files tab methods
		/**
		 * Open a file in the Nextcloud Files app
		 * @param {object} file - The file object to open
		 */
		openFile(file) {
			const dirPath = file.path.substring(0, file.path.lastIndexOf('/'))
			const cleanPath = dirPath.replace(/^\/admin\/files\//, '/')
			const filesAppUrl = `/index.php/apps/files/files/${file.id}?dir=${encodeURIComponent(cleanPath)}&openfile=true`
			window.open(filesAppUrl, '_blank')
		},
		/**
		 * Format file size for display
		 * @param {number} bytes - The file size in bytes
		 * @return {string} The formatted file size
		 */
		formatFileSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (bytes === 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
		},
		toggleSelectAllFiles(checked) {
			if (checked) {
				// Add all current page files to selection
				this.paginatedFiles.forEach(file => {
					if (!objectStore.selectedAttachments.includes(file.id)) {
						objectStore.selectedAttachments.push(file.id)
					}
				})
			} else {
				// Remove all current page files from selection
				const currentPageIds = this.paginatedFiles.map(file => file.id)
				objectStore.selectedAttachments = objectStore.selectedAttachments.filter(id => !currentPageIds.includes(id))
			}
		},
		toggleFileSelection(fileId, checked) {
			if (checked) {
				if (!objectStore.selectedAttachments.includes(fileId)) {
					objectStore.selectedAttachments.push(fileId)
				}
			} else {
				objectStore.selectedAttachments = objectStore.selectedAttachments.filter(id => id !== fileId)
			}
		},
		onFilesPageChanged(page) {
			if (!this.currentObject) return
			return this.refreshFiles({ _page: page, _limit: this.filesCurrentPageSize })
		},
		onFilesPageSizeChanged(pageSize) {
			if (!this.currentObject) return
			return this.refreshFiles({ _page: 1, _limit: pageSize })
		},
		async refreshFiles(params = {}) {
			const { registerId, schemaId } = this.getRegisterSchemaIds(this.currentObject)
			await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', params, {
				source: 'openregister',
				schema: schemaId,
				register: registerId,
			})
		},
		massSelectedFiles(operation, predicate) {
			const selected = objectStore.selectedAttachments || []
			if (selected.length === 0) return
			const ids = (this.paginatedFiles || [])
				.filter(f => selected.includes(f.id) && predicate(f))
				.map(f => f.id)
			if (ids.length === 0) return
			navigationStore.setDialog('massAttachment', { operation, attachments: ids })
		},
		publishSelectedFiles() {
			this.massSelectedFiles('publish', f => !f.accessUrl && !f.downloadUrl)
		},
		depublishSelectedFiles() {
			this.massSelectedFiles('depublish', f => f.accessUrl || f.downloadUrl)
		},
		async deleteSelectedFiles() {
			if (objectStore.selectedAttachments.length === 0) return

			try {
				this.fileIdsLoading = [...objectStore.selectedAttachments]

				const selectedFiles = this.paginatedFiles.filter(item =>
					objectStore.selectedAttachments.includes(item.id),
				)
				const { registerId, schemaId } = this.getRegisterSchemaIds(this.currentObject)

				for (const file of selectedFiles) {
					const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${this.currentObject.id}/files/${file.id}`
					const response = await fetch(endpoint, { method: 'DELETE' })
					if (!response.ok) {
						throw new Error(`Failed to delete file ${file.title || file.name}: ${response.statusText}`)
					}
				}

				await this.refreshFiles()
				catalogStore.fetchPublications()
				objectStore.selectedAttachments = []
			} catch (error) {
				console.error('Failed to delete selected files:', error)
			} finally {
				this.fileIdsLoading = []
			}
		},
		// action: 'publish' | 'depublish' | 'delete'
		async runFileAction(file, action) {
			const loadingList = action === 'delete' ? 'fileIdsLoading' : `${action}Loading`
			try {
				this[loadingList].push(file.id)
				const { registerId, schemaId } = this.getRegisterSchemaIds(this.currentObject)
				const base = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${this.currentObject.id}/files/${file.id}`
				const endpoint = action === 'delete' ? base : `${base}/${action}`
				const response = await fetch(endpoint, { method: action === 'delete' ? 'DELETE' : 'POST' })
				if (!response.ok) {
					throw new Error(`Failed to ${action} file: ${response.statusText}`)
				}
				await this.refreshFiles()
				catalogStore.fetchPublications()
			} catch (error) {
				console.error(`Failed to ${action} file:`, error)
			} finally {
				this[loadingList] = this[loadingList].filter(id => id !== file.id)
			}
		},
		publishFile(file) { return this.runFileAction(file, 'publish') },
		depublishFile(file) { return this.runFileAction(file, 'depublish') },
		deleteFile(file) { return this.runFileAction(file, 'delete') },
		editFileLabels(file) {
			this.editingTags = file.id
			this.editedTags = file.labels || []
		},
		cancelFileLabelEditing() {
			this.editingTags = null
			this.editedTags = []
		},
		addNewTag(newTag) {
			if (!newTag) return
			if (!this.labelOptionsEdit.options || !Array.isArray(this.labelOptionsEdit.options)) {
				this.labelOptionsEdit.options = []
			}
			if (!this.labelOptionsEdit.options.includes(newTag)) {
				this.labelOptionsEdit.options = [...this.labelOptionsEdit.options, newTag]
			}
			if (!this.editedTags || !Array.isArray(this.editedTags)) {
				this.editedTags = []
			}
			if (!this.editedTags.includes(newTag)) {
				this.editedTags = [...this.editedTags, newTag]
			}
		},
		async getAllTags() {
			this.tagsLoading = true
			try {
				const response = await fetch(
					'/index.php/apps/openregister/api/tags',
					{ method: 'get' },
				)
				const data = await response.json()

				const newLabelOptionsEdit = []
				const tags = data.map((tag) => tag)
				newLabelOptionsEdit.push(...tags)

				this.labelOptionsEdit.options = newLabelOptionsEdit
			} catch (error) {
				console.error('Error fetching tags:', error)
			} finally {
				this.tagsLoading = false
			}
		},
		async saveTags(file, editedTags) {
			try {
				const publication = this.currentObject
				const { registerId, schemaId } = this.getRegisterSchemaIds(publication)

				// Update file tags using the same approach as PublicationDetail.vue
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files/${file.id}`

				const response = await fetch(endpoint, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						tags: editedTags,
					}),
				})

				if (!response.ok) {
					throw new Error(`Failed to update file tags: ${response.statusText}`)
				}

				// Refresh files list with publication data
				const publicationData = {
					source: 'openregister',
					schema: schemaId,
					register: registerId,
				}
				await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', {}, publicationData)

				this.editingTags = null
				this.editedTags = []
			} catch (error) {
				console.error('Error saving tags:', error)
			}
		},
		// Utility method to get register and schema IDs from publication object
		getRegisterSchemaIds(publication) {
			const registerId = typeof publication['@self'].register === 'object'
				? publication['@self'].register?.id || publication['@self'].register?.uuid
				: publication['@self'].register
			const schemaId = typeof publication['@self'].schema === 'object'
				? publication['@self'].schema?.id || publication['@self'].schema?.uuid
				: publication['@self'].schema
			return { registerId, schemaId }
		},
		// Action button methods
		uploadFiles() {
			// Open the upload files modal (same as in PublicationDetail.vue)
			navigationStore.setDialog('uploadFiles')
		},
		shouldShowPublishAction(object) {
			if (!object || !object['@self']) return false
			return object['@self'].published === null || object['@self'].published === undefined
		},
		shouldShowDepublishAction(object) {
			if (!object || !object['@self']) return false
			return object['@self'].published !== null && object['@self'].published !== undefined
		},
		openSingleObjectDialog(dialog) {
			if (!this.currentObject) return
			objectStore.setSelectedObjects([{
				...this.currentObject,
				id: this.currentObject['@self']?.id || this.currentObject.id,
			}])
			navigationStore.setDialog(dialog)
		},
		singlePublishObject() { this.openSingleObjectDialog('massPublishObjects') },
		singleDepublishObject() { this.openSingleObjectDialog('massDepublishObjects') },
		singleDeleteObject() { this.openSingleObjectDialog('massDeleteObject') },
		// Schema handling methods
		getSchemaProperties() {
			let properties = {}

			// For new objects, use the selected schema
			if (this.isNewObject && this.selectedSchema) {
				const fullSchema = objectStore.availableSchemas.find(schema => schema.id === this.selectedSchema.id)
				properties = fullSchema?.properties || {}
			} else if (this.currentObject && this.currentObject['@self']?.schema) {
				// For existing objects, try to get schema from the object's schema reference
				const schemaRef = this.currentObject['@self'].schema
				let schemaId = null

				// Handle both object and string schema references
				if (typeof schemaRef === 'object') {
					schemaId = schemaRef.id || schemaRef.uuid
				} else {
					schemaId = schemaRef
				}

				if (schemaId) {
					const fullSchema = objectStore.availableSchemas.find(schema => schema.id === schemaId)
					if (fullSchema?.properties) {
						properties = fullSchema.properties
					}
				}
			}

			return properties
		},
		// Helper method to rebuild object with schema properties after API operations
		rebuildObjectWithSchemaProperties(apiResult) {
			// Start with the API result merged with current object
			const mergedObject = {
				...this.currentObject,
				...apiResult,
				'@self': {
					...this.currentObject['@self'],
					...apiResult['@self'],
				},
			}

			// Get schema properties to ensure we don't lose any
			const schemaProperties = this.getSchemaProperties()

			// Add missing schema properties with default values
			for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
				if (!Object.prototype.hasOwnProperty.call(mergedObject, key)) {
					// Add with appropriate default value based on type
					let defaultValue = ''
					switch (schemaProperty.type) {
					case 'string':
						defaultValue = schemaProperty.const || ''
						break
					case 'number':
					case 'integer':
						defaultValue = 0
						break
					case 'boolean':
						defaultValue = false
						break
					case 'array':
						defaultValue = []
						break
					case 'object':
						defaultValue = {}
						break
					default:
						defaultValue = ''
					}
					mergedObject[key] = defaultValue
				}
			}

			return mergedObject
		},

		/**
		 * Clean formData to ensure it's a proper object with correct property keys
		 * @return {object} Cleaned form data object
		 */
		cleanFormData() {
			const cleaned = {}

			// If formData is somehow an array, convert it properly
			if (Array.isArray(this.formData)) {
				// This should never happen, but just in case
				return {}
			}

			// Copy all valid properties from formData
			for (const [key, value] of Object.entries(this.formData || {})) {
				// Only include valid property names:
				// - Must be a string
				// - Must not be empty
				// - Must not be purely numeric (array indices)
				// - Must not be special Vue/internal keys
				if (typeof key === 'string'
					&& key.length > 0
					&& !/^\d+$/.test(key)
					&& !key.startsWith('_')
					&& !key.startsWith('$')) {
					cleaned[key] = value
				}
			}

			return cleaned
		},

		/**
		 * Build complete object data including all schema properties
		 * @return {object} Complete object with all properties from schema and current object
		 */
		buildCompleteObjectData() {
			const schemaProperties = this.getSchemaProperties()
			const cleanedFormData = this.cleanFormData()
			const currentObjectData = this.currentObject || {}

			// Start with current object data (excluding id but keeping @self for updates)
			const objectData = {}
			for (const [key, value] of Object.entries(currentObjectData)) {
				if (key !== 'id') {
					objectData[key] = value
				}
			}

			// Add all schema properties with appropriate values
			for (const [propertyKey, schemaProperty] of Object.entries(schemaProperties)) {
				if (Object.prototype.hasOwnProperty.call(cleanedFormData, propertyKey)) {
					// Check if property was marked for deletion (undefined)
					if (cleanedFormData[propertyKey] === undefined) {
						// For schema properties, don't include undefined values - let backend handle defaults
						// This effectively removes the property from the payload
						delete objectData[propertyKey]
					} else {
						// Use edited value from formData; normalize arrays if schema type is array
						const formValue = cleanedFormData[propertyKey]
						if (schemaProperty.type === 'array') {
							let normalized
							if (Array.isArray(formValue)) {
								normalized = formValue
							} else if (typeof formValue === 'string') {
								normalized = formValue.split(/ *, */g).filter(Boolean)
							} else if (formValue === null) {
								normalized = null
							} else if (formValue === false) {
								// Edge case: some inputs may pass boolean false; treat as empty array
								normalized = []
							} else {
								normalized = [String(formValue)].filter(Boolean)
							}
							objectData[propertyKey] = normalized
						} else {
							objectData[propertyKey] = formValue
						}
					}
				} else if (Object.prototype.hasOwnProperty.call(currentObjectData, propertyKey)) {
					// Keep existing value from current object
					objectData[propertyKey] = currentObjectData[propertyKey]
				} else {
					// Property doesn't exist, set appropriate default or null
					let defaultValue = null

					// Only set non-null defaults for specific cases
					switch (schemaProperty.type) {
					case 'string':
						defaultValue = schemaProperty.const || null
						break
					case 'number':
					case 'integer':
						defaultValue = null // Let backend handle defaults
						break
					case 'boolean':
						defaultValue = null // Let backend handle defaults
						break
					case 'array':
						defaultValue = null // Let backend handle defaults
						break
					case 'object':
						defaultValue = null // Let backend handle defaults
						break
					default:
						defaultValue = null
					}

					objectData[propertyKey] = defaultValue
				}
			}

			// Also include any edited properties that might not be in the schema
			// But only include valid property names (not numeric indices) and not undefined values
			for (const [key, value] of Object.entries(cleanedFormData)) {
				if (!Object.prototype.hasOwnProperty.call(schemaProperties, key)
					&& typeof key === 'string'
					&& key.length > 0
					&& !/^\d+$/.test(key)
					&& value !== undefined) { // Don't include properties marked for deletion
					objectData[key] = value
				}
			}

			return objectData
		},

		// Property dropping methods
		canDropProperty(key, value) {
			// Don't show drop button for metadata properties
			if (key === '@self' || key === 'id') {
				return false
			}

			// Don't show drop button for const or immutable properties
			if (this.isConstantOrImmutable(key)) {
				return false
			}

			// Show drop button if:
			// 1. Property has a value (either in formData or original object)
			// 2. Property exists in current object or has been edited
			const hasFormValue = this.formData[key] !== undefined
			const hasOriginalValue = this.currentObject && Object.prototype.hasOwnProperty.call(this.currentObject, key)

			return hasFormValue || hasOriginalValue
		},
		/**
		 * Check if a property is constant or immutable
		 * @param {string} key - Property key
		 * @return {boolean} True if property is constant or immutable
		 */
		isConstantOrImmutable(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			// Check by property name patterns (case insensitive)
			const immutablePatterns = ['immutable', 'readonly', 'constant']
			const isImmutableByName = immutablePatterns.some(pattern =>
				key.toLowerCase().includes(pattern),
			)

			if (schemaProperty) {
				const isConstant = schemaProperty.const !== undefined
				const isImmutable = schemaProperty.readOnly === true || schemaProperty.immutable === true

				// Debug: Log all detection methods
				if (process.env.NODE_ENV === 'development') {
					// eslint-disable-next-line no-console
					console.log(`Property ${key} detection:`, {
						isConstant,
						isImmutable,
						isImmutableByName,
						schemaProperty,
					})
				}

				return isConstant || isImmutable || isImmutableByName
			}

			return isImmutableByName
		},

		getDropPropertyTooltip(key) {
			const schemaProperties = this.getSchemaProperties()
			const isSchemaProperty = Object.prototype.hasOwnProperty.call(schemaProperties, key)

			if (isSchemaProperty) {
				return `Reset '${this.getPropertyDisplayName(key)}' to empty value`
			} else {
				return `Remove '${this.getPropertyDisplayName(key)}' property completely`
			}
		},

		dropProperty(key) {
			const schemaProperties = this.getSchemaProperties()
			const isSchemaProperty = Object.prototype.hasOwnProperty.call(schemaProperties, key)

			if (isSchemaProperty) {
				// For schema properties, reset to appropriate default/null value
				const schemaProperty = schemaProperties[key]
				let defaultValue = null

				switch (schemaProperty.type) {
				case 'string':
					defaultValue = schemaProperty.const || ''
					break
				case 'number':
				case 'integer':
					defaultValue = 0
					break
				case 'boolean':
					defaultValue = false
					break
				case 'array':
					defaultValue = []
					break
				case 'object':
					defaultValue = {}
					break
				default:
					defaultValue = ''
				}

				// Set the default value in formData
				this.$set(this.formData, key, defaultValue)
			} else {
				// For non-schema properties, remove completely from formData
				if (this.formData[key] !== undefined) {
					this.$delete(this.formData, key)
				}

				// If it was in the original object, we need to track its removal
				// We'll set it to a special marker that indicates deletion
				if (this.currentObject && Object.prototype.hasOwnProperty.call(this.currentObject, key)) {
					this.$set(this.formData, key, undefined)
				}
			}

			// Clear selection if this property was selected
			if (this.selectedProperty === key) {
				this.selectedProperty = null
			}
		},

		// Property-cell helpers used by CnPropertiesTab template

		/**
		 * Bridge between CnPropertiesTab's `update:property-value` event and `formData`.
		 * @param {{ key: string, value: * }} payload - The property key and its new value.
		 */
		onPropertyValueUpdate({ key, value }) {
			if (!this.formData || Array.isArray(this.formData)) {
				this.formData = {}
			}
			this.$set(this.formData, key, value)
		},

		onEditorLoad({ propertyKey, editor }) {
			this.markdownEditors[propertyKey] = editor
		},

		onEditorBlur({ propertyKey, onUpdate }) {
			onUpdate(this.getMarkdownContent(this.markdownEditors[propertyKey]))
		},

		/**
		 * Whether a schema property should render with the Toast UI markdown editor.
		 * @param {object} schemaProp - The JSON-schema entry for the property.
		 * @return {boolean}
		 */
		isMarkdownProperty(schemaProp) {
			return !!(schemaProp && schemaProp.type === 'string' && schemaProp.format === 'markdown')
		},

		/**
		 * Extract the current content from a Toast UI Editor instance, preferring markdown.
		 * @param {object} editorInstance - The Toast UI Editor instance from `@load`.
		 * @return {string}
		 */
		getMarkdownContent(editorInstance) {
			try {
				if (editorInstance && typeof editorInstance.getMarkdown === 'function') {
					return editorInstance.getMarkdown()
				}
				if (editorInstance && typeof editorInstance.getHTML === 'function') {
					return editorInstance.getHTML()
				}
				return typeof editorInstance === 'string' ? editorInstance : ''
			} catch (error) {
				console.warn('Error getting content from markdown editor:', error)
				return ''
			}
		},
	},
}
</script>

<style scoped>
/* ViewObject-specific overrides only */

.multi-actions-container {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 10px;
}

/* Actions header cell styling for toggle button */
.actions-header-cell {
	text-align: right !important;
	vertical-align: middle !important;
	padding-right: 8px !important;
}

/* Toggle button styling */
.eye-toggle-btn {
	float: right;
	margin: 0;
}

/* Inline editing styles */
.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background-color: var(--color-background-hover);
}

.viewTableRow.selected-row {
	background-color: var(--color-primary-light);
}

.viewTableRow.edited-row {
	background-color: var(--color-success-light);
	border-left: 3px solid var(--color-success);
}

.viewTableRow.edited-row.selected-row {
	background-color: var(--color-primary-light);
	border-left: 3px solid var(--color-success);
}

.viewTableRow.property-invalid {
	background-color: var(--color-error-light);
	border-left: 4px solid var(--color-error);
}

.viewTableRow.property-warning {
	background-color: var(--color-warning-light);
	border-left: 4px solid var(--color-warning);
}

.viewTableRow.property-new {
	background-color: var(--color-primary-element-light);
	border-left: 4px solid var(--color-primary-element);
}

.viewTableRow.property-valid {
	border-left: 4px solid var(--color-success);
}

.prop-cell-content {
	display: flex;
	align-items: center;
	gap: 8px;
	text-align: left;
}

.value-cell-content {
	display: flex;
	align-items: center;
	justify-content: space-between;
	text-align: left;
	width: 100%;
}

.value-input-container {
	flex: 1;
	text-align: left;
}

.drop-property-btn {
	opacity: 0.3 !important;
	transition: .2s ease !important;
	margin-left: auto;
	flex-shrink: 0;
}

.drop-property-btn:hover {
	opacity: 1 !important;
	background-color: var(--color-error-hover) !important;
	color: white !important;
}

.validation-icon {
	flex-shrink: 0;
}

.error-icon {
	color: var(--color-error);
}

.warning-icon {
	color: var(--color-warning);
}

.lock-icon {
	color: var(--color-text-lighter);
}

.new-icon {
	color: var(--color-primary-element);
}

.viewTableRow.non-editable-row {
	background-color: var(--color-background-dark);
	cursor: not-allowed;
	opacity: 0.7;
}

.viewTableRow.non-editable-row:hover {
	background-color: var(--color-background-dark);
}

.value-cell {
	position: relative;
	text-align: left;
}

.value-input-container {
	flex: 1;
	text-align: left;
	padding: 0;
	margin: 0;
	width: 100%;
}

.value-input-container .text-field {
	margin: 0;
	padding: 0;
}

/* Ensure proper alignment for table cells */
.tableColumnConstrained {
	text-align: left;
	align-items: center;
}

.tableColumnExpanded {
	text-align: left;
	align-items: center;
	white-space: normal;
	word-break: break-word;
}

.json-value {
	max-height: 200px;
	overflow-y: auto;
	white-space: pre-wrap;
	font-family: monospace;
	font-size: 12px;
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: 4px;
	margin: 0;
}

/* File name and status icons layout */
.file-name-container {
	display: flex;
	align-items: center;
	width: 100%;
	gap: 8px;
}

.file-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.file-status-icons {
	flex-shrink: 0;
}

.warningIcon {
	color: var(--color-warning);
}

.publishedIcon {
	color: var(--color-success);
}

.tab-title {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 0.5rem;
}

/* Selection flow styles */
.selectionContainer {
	padding: 2rem;
	display: flex;
	flex-direction: column;
	gap: 2rem;
	max-width: 600px;
	margin: 0 auto;
}

.selectionStep {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.selectionStep h3 {
	margin: 0;
	font-size: 1.2rem;
	color: var(--color-main-text);
}

.selectionStep p {
	margin: 0;
	color: var(--color-text-lighter);
	font-size: 0.9rem;
}

.selectionStep:last-child {
	text-align: center;
}

/* Modal header styles */
.modal-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.modal-title {
	font-weight: 600;
	color: var(--color-main-text);
}

.status-icon {
	flex-shrink: 0;
}

.published-icon {
	color: var(--color-success);
}

.draft-icon {
	color: var(--color-warning);
}

.depublished-icon {
	color: var(--color-error);
}

/* Files info card */
.files-info-card {
	margin-bottom: 1rem;
}

.files-info-card p {
	margin: 0.5rem 0;
}

.files-info-card p:first-child {
	margin-top: 0;
}

.files-info-card p:last-child {
	margin-bottom: 0;
}

/* Modal header styling */
.dialog__name {
	display: flex !important;
	align-items: center !important;
	justify-content: center !important;
	gap: 0.75rem !important;
	margin: 0 !important;
	font-size: 1.25rem !important;
	font-weight: 600 !important;
	text-align: center !important;
}

.dialog__name .status-icon {
	flex-shrink: 0;
}

/* Textarea and Rich content editor specific styles */
.value-input-container .nc-textarea,
.value-input-container .rich-contenteditable {
	width: 100%;
	margin: 0;
}

.value-input-container .rich-contenteditable {
	min-height: 100px;
}

/* Ensure proper spacing for multi-line inputs */
.value-input-container .nc-textarea textarea {
	min-height: 100px;
	resize: vertical;
	white-space: pre-wrap;
	word-break: break-word;
	overflow-wrap: anywhere;
}

/* Toast UI Editor - Basic Nextcloud Integration */
.value-input-container .toastui-editor-defaultUI {
	font-family: var(--font-face) !important;
	font-size: var(--default-font-size) !important;
	background-color: var(--color-main-background) !important;
	width: 100% !important;
}

/* Toolbar styling */
.value-input-container .toastui-editor-toolbar {
	background-color: var(--color-background-hover) !important;
	border-bottom: 1px solid var(--color-border-dark) !important;
	padding: 8px !important;
}

.value-input-container .toastui-editor-toolbar-icons button {
	color: var(--color-main-text) !important;
	background-color: transparent !important;
	border: none !important;
	border-radius: var(--border-radius) !important;
	padding: 6px !important;
	margin: 2px !important;
}

.value-input-container .toastui-editor-toolbar-icons button:hover {
	background-color: var(--color-background-dark) !important;
}

.value-input-container .toastui-editor-toolbar-icons button.active {
	background-color: var(--color-primary-element) !important;
	color: var(--color-primary-element-text) !important;
}

/* Hide mode switch for WYSIWYG-only */
.value-input-container .toastui-editor-mode-switch {
	display: none !important;
}

/* Editor content styling */
.value-input-container .toastui-editor-contents {
	color: var(--color-main-text) !important;
	font-family: var(--font-face) !important;
}

.value-input-container .ProseMirror {
	background-color: var(--color-main-background) !important;
	color: var(--color-main-text) !important;
	font-family: var(--font-face) !important;
	font-size: var(--default-font-size) !important;
	padding: 12px !important;
	min-height: 200px !important;
}

.input-with-icon {
	display: flex;
	align-items: center;
	gap: 8px;
}

.view-object-datepicker {
	z-index: 12000 !important;
}

/* Label editing styles (from UploadFiles.vue) */
.files-list__row-action-system-tags {
	margin-right: 7px;
	display: flex;
}

.files-list__system-tags {
	--min-size: 32px;
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-start;
	align-items: flex-start;
	gap: 5px;
	min-width: calc(var(--min-size) * 2);
	max-width: 100%;
	list-style: none;
	margin: 0;
	padding: 0;
}

.files-list__system-tag {
	padding: 5px 10px;
	border: 1px solid;
	border-radius: var(--border-radius-pill);
	border-color: var(--color-border);
	color: var(--color-text-maxcontrast);
	min-height: var(--min-size);
	display: inline-block;
	line-height: 1.3;
	text-align: center;
	box-sizing: border-box;
}

.files-list__system-tag:not(:first-child) {
	margin-inline-start: 5px;
}

.editTagsButton {
	margin-inline-end: 3px;
	margin-inline-start: 3px;
}

.fileLabelsContainer {
	display: flex;
	justify-content: space-between;
	text-align: unset;
	align-items: center;
	box-sizing: border-box;
}

.inline-actions {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-right: 8px;
}

.label-edit-container {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 100%;
	max-width: 280px;
}

.label-edit-container .nc-select {
	flex: 1;
	min-width: 120px;
	max-width: 160px;
}

.label-edit-container .editTagsButton {
	flex-shrink: 0;
	margin-left: 2px;
}

.viewObjectDialog .viewTable {
	table-layout: fixed;
}

.viewObjectDialog .viewTable th,
.viewObjectDialog .viewTable td {
	white-space: normal;
	word-break: break-word;
}

.viewObjectDialog .viewTable td.td-labels {
	white-space: nowrap;
	word-break: unset;
}

.value-cell-content {
	flex-wrap: wrap;
}

.viewObjectDialog .viewTable td.table-row-type {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
	word-break: unset !important;
}

.viewObjectDialog .viewTable td.short-column {
	width: 100px;
}

.viewObjectDialog .viewTable td.table-row-title {
	flex: 1;
	white-space: normal;
	word-break: break-word;
}

.short-column {
    width: 100px;
    max-width: 100px;
    overflow: hidden;
	text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.table-row-title {
    width: 100%;
    max-width: initial;
	white-space: normal;
	word-break: break-word;
}

.table-row-type {
	width: 120px;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	word-break: unset !important;
}

.table-row-actions {
	width: 35px;
	text-align: center;
}

.table-row-labels {
	width: 315px;
	max-width: 315px;
}

.td-labels {
	width: 100px;
	max-width: 100px;
	flex-wrap: wrap;
}

.viewObjectDialog .viewTable th.table-row-title,
.viewObjectDialog .viewTable td.table-row-title {
    width: 100%;
}
</style>
