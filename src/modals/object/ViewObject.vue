/**
 * @file ViewObject.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<div>
		<NcDialog v-if="navigationStore.modal === 'viewObject'"
			:name="getModalTitle()"
			size="large"
			:can-close="true"
			@update:open="handleDialogClose">
			<div class="formContainer viewObjectDialog">
				<!-- Display Object -->
				<div v-if="objectStore.getActiveObject('publication')">
					<div class="tabContainer">
						<BTabs v-model="activeTab" content-class="mt-3" justified>
							<BTab title="Properties" active>
								<div class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnConstrained">
													Property
												</th>
												<th class="tableColumnExpanded">
													Value
												</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="([key, value]) in objectProperties"
												:key="key"
												class="viewTableRow"
												:class="{
													'selected-row': selectedProperty === key,
													'edited-row': formData[key] !== undefined,
													'non-editable-row': !isPropertyEditable(key, formData[key] !== undefined ? formData[key] : value),
													...getPropertyValidationClass(key, value)
												}"
												@click="handleRowClick(key, $event)">
												<td class="tableColumnConstrained prop-cell">
													<div class="prop-cell-content">
														<AlertCircle v-if="getPropertyValidationClass(key, value) === 'property-invalid'"
															v-tooltip="getPropertyErrorMessage(key, value)"
															class="validation-icon error-icon"
															:size="16" />
														<Alert v-else-if="getPropertyValidationClass(key, value) === 'property-warning'"
															v-tooltip="getPropertyWarningMessage(key, value)"
															class="validation-icon warning-icon"
															:size="16" />
														<Plus v-else-if="getPropertyValidationClass(key, value) === 'property-new'"
															v-tooltip="getPropertyNewMessage(key)"
															class="validation-icon new-icon"
															:size="16" />
														<LockOutline v-else-if="!isPropertyEditable(key, formData[key] !== undefined ? formData[key] : value)"
															v-tooltip="getEditabilityWarning(key, formData[key] !== undefined ? formData[key] : value)"
															class="validation-icon lock-icon"
															:size="16" />
														<span
															v-tooltip="getPropertyTooltip(key)">
															{{ getPropertyDisplayName(key) }}
														</span>
													</div>
												</td>
												<td class="tableColumnExpanded value-cell">
													<div v-if="selectedProperty === key && isPropertyEditable(key, formData[key] !== undefined ? formData[key] : value)" class="value-input-container" @click.stop>
														<!-- Boolean properties -->
														<NcCheckboxRadioSwitch
															v-if="getPropertyInputComponent(key) === 'NcCheckboxRadioSwitch'"
															:checked="formData[key] !== undefined ? formData[key] : value"
															type="switch"
															@update:checked="updatePropertyValue(key, $event)">
															{{ getPropertyDisplayName(key) }}
														</NcCheckboxRadioSwitch>

														<!-- Date/Time properties -->
														<NcDateTimePickerNative
															v-else-if="getPropertyInputComponent(key) === 'NcDateTimePickerNative'"
															:value="formData[key] !== undefined ? formData[key] : value"
															:type="getPropertyInputType(key)"
															:label="getPropertyDisplayName(key)"
															@update:value="updatePropertyValue(key, $event)" />

														<!-- Text/Number properties -->
														<NcTextField
															v-else
															ref="propertyValueInput"
															:value="String(formData[key] !== undefined ? formData[key] : value || '')"
															:type="getPropertyInputType(key)"
															:placeholder="getPropertyDisplayName(key)"
															:min="getPropertyMinimum(key)"
															:max="getPropertyMaximum(key)"
															:step="getPropertyStep(key)"
															@update:value="updatePropertyValue(key, $event)" />
													</div>
													<div v-else>
														<template v-if="formData[key] !== undefined">
															<!-- Show edited value -->
															<pre
																v-if="typeof formData[key] === 'object' && formData[key] !== null"
																v-tooltip="'JSON object (edited)'"
																class="json-value">{{ formatValue(formData[key]) }}</pre>
															<span
																v-else-if="isValidDate(formData[key])"
																v-tooltip="`Date: ${new Date(formData[key]).toISOString()} (edited)`">{{ new Date(formData[key]).toLocaleString() }}</span>
															<span
																v-else
																v-tooltip="getPropertyTooltip(key)">{{ getDisplayValue(key, value) }}</span>
														</template>
														<template v-else>
															<!-- Show original value -->
															<pre
																v-if="typeof value === 'object' && value !== null"
																v-tooltip="'JSON object'"
																class="json-value">{{ formatValue(value) }}</pre>
															<span
																v-else-if="isValidDate(value)"
																v-tooltip="`Date: ${new Date(value).toISOString()}`">{{ new Date(value).toLocaleString() }}</span>
															<span
																v-else
																v-tooltip="getPropertyTooltip(key)">{{ getDisplayValue(key, value) }}</span>
														</template>
													</div>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</BTab>
							<BTab title="Metadata">
								<div class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnConstrained">
													Metadata
												</th>
												<th class="tableColumnExpanded">
													Value
												</th>
												<th class="tableColumnActions">
													Actions
												</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="([key, value, hasAction]) in metadataProperties"
												:key="key"
												class="viewTableRow">
												<td class="tableColumnConstrained">
													{{ key }}
												</td>
												<td class="tableColumnExpanded">
													{{ value }}
												</td>
												<td class="tableColumnActions">
													<NcButton
														v-if="hasAction && key === 'ID'"
														class="copy-button"
														size="small"
														@click="copyToClipboard(currentObject.id)">
														<template #icon>
															<Check v-if="isCopied" :size="16" />
															<ContentCopy v-else :size="16" />
														</template>
														{{ isCopied ? 'Copied' : 'Copy' }}
													</NcButton>
													<NcButton
														v-else-if="hasAction && key === 'Published'"
														:disabled="isPublishing"
														size="small"
														@click="openPublishModal">
														<template #icon>
															<NcLoadingIcon v-if="isPublishing" :size="16" />
															<Publish v-else :size="16" />
														</template>
														Change
													</NcButton>
													<NcButton
														v-else-if="hasAction && key === 'Depublished'"
														:disabled="isDepublishing"
														size="small"
														@click="openDepublishModal">
														<template #icon>
															<NcLoadingIcon v-if="isDepublishing" :size="16" />
															<PublishOff v-else :size="16" />
														</template>
														Change
													</NcButton>
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</BTab>
							<BTab title="Data">
								<NcNoteCard v-if="success" type="success" class="note-card">
									<p>Object successfully modified</p>
								</NcNoteCard>
								<div class="json-editor">
									<div :class="`codeMirrorContainer ${getTheme()}`">
										<CodeMirror
											v-model="jsonData"
											:basic="true"
											placeholder="{ &quot;key&quot;: &quot;value&quot; }"
											:dark="getTheme() === 'dark'"
											:linter="jsonParseLinter()"
											:lang="json()"
											:extensions="[json()]"
											:tab-size="2"
											style="height: 400px" />
										<NcButton
											class="format-json-button"
											type="secondary"
											size="small"
											@click="formatJSON">
											Format JSON
										</NcButton>
									</div>
									<span v-if="!isValidJson(jsonData)" class="error-message">
										Invalid JSON format
									</span>
								</div>
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
				<NcButton type="primary" :disabled="isSaving" @click="saveObject">
					<template #icon>
						<NcLoadingIcon v-if="isSaving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ isSaving ? 'Saving...' : 'Save' }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Publish Object Modal -->
		<NcDialog :open="showPublishModal"
			name="Publish Object"
			size="small"
			:style="{ zIndex: 10001 }"
			@update:open="showPublishModal = $event">
			<div class="modal-content">
				<p>Set the publication date for this object. Leave empty to NOT publish this object.</p>

				<NcDateTimePickerNative
					v-model="publishDate"
					label="Publication Date"
					type="datetime-local" />
			</div>

			<template #actions>
				<NcButton @click="closePublishModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton type="primary"
					:disabled="isPublishing"
					@click="publishObject">
					<template #icon>
						<NcLoadingIcon v-if="isPublishing" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ isPublishing ? 'Publishing...' : 'Save' }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Depublish Object Modal -->
		<NcDialog :open="showDepublishModal"
			name="Depublish Object"
			size="small"
			:style="{ zIndex: 10001 }"
			@update:open="showDepublishModal = $event">
			<div class="modal-content">
				<p>Set the depublication date for this object. Leave empty to NOT depublish this object.</p>

				<NcDateTimePickerNative
					v-model="depublishDate"
					label="Depublication Date"
					type="datetime-local" />
			</div>

			<template #actions>
				<NcButton @click="closeDepublishModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					Cancel
				</NcButton>
				<NcButton type="primary"
					:disabled="isDepublishing"
					@click="depublishObject">
					<template #icon>
						<NcLoadingIcon v-if="isDepublishing" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ isDepublishing ? 'Depublishing...' : 'Save' }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcNoteCard,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcDateTimePickerNative,
} from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'
import { BTabs, BTab } from 'bootstrap-vue'
import { getTheme } from '../../services/getTheme.js'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'

export default {
	name: 'ViewObject',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcDateTimePickerNative,
		CodeMirror,
		BTabs,
		BTab,
		Cancel,
		ContentCopy,
		Check,
		ContentSave,
		LockOutline,
		Alert,
		AlertCircle,
		Plus,
		Publish,
		PublishOff,
	},
	data() {
		return {
			activeTab: 0,
			formData: {},
			jsonData: '',
			selectedProperty: null,
			isSaving: false,
			success: null,
			error: null,
			isCopied: false,
			// Object publish/depublish modal states
			showPublishModal: false,
			showDepublishModal: false,
			publishDate: null,
			depublishDate: null,
			isPublishing: false,
			isDepublishing: false,
		}
	},
	computed: {
		currentObject() {
			return objectStore.getActiveObject('publication')
		},
		objectProperties() {
			// Return array of [key, value] pairs, excluding '@self' and 'id'
			if (!this.currentObject) return []
			
			const objectData = this.currentObject
			const schemaProperties = {} // TODO: Get schema properties when available
			
			// Start with properties that exist in the object
			const existingProperties = Object.entries(objectData)
				.filter(([key]) => key !== '@self' && key !== 'id')
			
			// Add schema properties that don't exist in the object yet
			const missingSchemaProperties = []
			for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
				if (!objectData.hasOwnProperty(key)) {
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
					missingSchemaProperties.push([key, defaultValue])
				}
			}
			
			// Combine existing properties and missing schema properties
			return [...existingProperties, ...missingSchemaProperties]
		},
		metadataProperties() {
			// Return array of [key, value, hasAction] for metadata display
			if (!this.currentObject) return []

			const obj = this.currentObject
			const metadata = []

			// ID with copy action
			metadata.push([
				'ID',
				obj.id || 'Not set',
				true,
			])

			// Version
			metadata.push([
				'Version',
				obj['@self']?.version || 'Not set',
				false,
			])

			// Created
			metadata.push([
				'Created',
				obj['@self']?.created ? new Date(obj['@self'].created).toLocaleString() : 'Not set',
				false,
			])

			// Updated
			metadata.push([
				'Updated',
				obj['@self']?.updated ? new Date(obj['@self'].updated).toLocaleString() : 'Not set',
				false,
			])

			// Published with change action
			metadata.push([
				'Published',
				obj['@self']?.published ? new Date(obj['@self'].published).toLocaleString() : 'Not published',
				true,
			])

			// Depublished with change action
			metadata.push([
				'Depublished',
				obj['@self']?.depublished ? new Date(obj['@self'].depublished).toLocaleString() : 'Not depublished',
				true,
			])

			return metadata
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
		jsonData: {
			handler(newValue) {
				if (this.isValidJson(newValue)) {
					this.updateFormFromJson()
				}
			},
		},
		formData: {
			deep: true,
			immediate: true,
			handler(obj) {
				// Create a clean copy of the form data
				const draft = JSON.stringify(obj, null, 2)
				// Only update if the content is different to avoid infinite loops
				if (this.jsonData !== draft) {
					this.jsonData = draft
				}
			},
		},
	},
	mounted() {
		this.initializeData()
	},
	methods: {
		getModalTitle() {
			if (!this.currentObject) return 'View Object'
			
			const name = this.currentObject['@self']?.name 
				|| this.currentObject.name 
				|| this.currentObject.title
				|| this.currentObject.id
			
			return `${name} (Publication)`
		},
		closeModal() {
			// Clear state first
			this.activeTab = 0
			this.success = null
			this.error = null
			this.isCopied = false
			this.selectedProperty = null

			// Clear publish/depublish modal states
			this.showPublishModal = false
			this.showDepublishModal = false
			this.publishDate = null
			this.depublishDate = null
			this.isPublishing = false
			this.isDepublishing = false

			// Close modal
			navigationStore.setModal(null)
		},
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},
		initializeData() {
			if (!this.currentObject) {
				this.formData = {}
				this.jsonData = JSON.stringify({ data: {} }, null, 2)
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

			// Create a safe copy for formData
			try {
				this.formData = JSON.parse(JSON.stringify(filtered))
			} catch (e) {
				// Fallback if JSON serialization fails
				this.formData = { ...filtered }
			}
			this.jsonData = JSON.stringify(filtered, null, 2)
		},
		async saveObject() {
			this.isSaving = true
			this.error = null

			try {
				// For now, just show success - implement actual save logic later
				this.success = true
				setTimeout(() => {
					this.success = null
				}, 2000)
			} catch (e) {
				this.error = e.message || 'Failed to save object'
				this.success = false
			} finally {
				this.isSaving = false
			}
		},
		updateFormFromJson() {
			try {
				const parsed = JSON.parse(this.jsonData)
				this.formData = parsed
			} catch (e) {
				this.error = 'Invalid JSON format'
			}
		},
		isValidJson(str) {
			if (!str || !str.trim()) {
				return false
			}
			try {
				JSON.parse(str)
				return true
			} catch (e) {
				return false
			}
		},
		formatJSON() {
			try {
				if (this.jsonData) {
					const parsed = JSON.parse(this.jsonData)
					this.jsonData = JSON.stringify(parsed, null, 2)
				}
			} catch (e) {
				// Keep invalid JSON as-is
			}
		},
		isValidDate(value) {
			if (!value) return false
			const date = new Date(value)
			return date instanceof Date && !isNaN(date)
		},
		formatValue(val) {
			return JSON.stringify(val, null, 2)
		},
		getTheme,
		async copyToClipboard(text) {
			try {
				await navigator.clipboard.writeText(text)
				this.isCopied = true
				setTimeout(() => { this.isCopied = false }, 2000)
			} catch (err) {
				console.error('Failed to copy text:', err)
			}
		},
		// Property validation and editing methods
		getPropertyValidationClass(key, value) {
			// For now, return empty - implement validation logic later
			return ''
		},
		getPropertyErrorMessage(key, value) {
			return `Property '${key}' has an invalid value`
		},
		getPropertyWarningMessage(key, value) {
			return `Property '${key}' exists in the object but is not defined in the current schema.`
		},
		getPropertyNewMessage(key) {
			return `Property '${key}' is defined in the schema but doesn't have a value yet. Click to add a value.`
		},
		isPropertyEditable(key, value) {
			// For now, allow editing all properties - implement schema-based logic later
			return true
		},
		getEditabilityWarning(key, value) {
			return null
		},
		handleRowClick(key, event) {
			// Don't select if clicking on an input or button
			if (event.target.tagName === 'INPUT' || event.target.tagName === 'BUTTON' || event.target.closest('.value-input-container')) {
				return
			}

			// Don't deselect if already selected
			if (this.selectedProperty === key) {
				return
			}

			// Check if property is editable
			const value = this.formData[key] !== undefined ? this.formData[key] : this.objectProperties.find(([k]) => k === key)?.[1]
			if (!this.isPropertyEditable(key, value)) {
				return
			}

			this.selectProperty(key)
		},
		selectProperty(key) {
			this.selectedProperty = key

			// Focus the input field after Vue updates the DOM
			this.$nextTick(() => {
				if (this.$refs.propertyValueInput && this.$refs.propertyValueInput[0]) {
					const input = this.$refs.propertyValueInput[0].$el.querySelector('input')
					if (input) {
						input.focus()
						input.select()
					}
				}
			})
		},
		updatePropertyValue(key, newValue) {
			// Update the form data using Vue 2 reactivity
			this.$set(this.formData, key, newValue)
		},
		getPropertyInputType(key) {
			return 'text' // Default for now
		},
		getPropertyInputComponent(key) {
			return 'NcTextField' // Default for now
		},
		getPropertyDisplayName(key) {
			return key // Use key as display name for now
		},
		getPropertyTooltip(key) {
			return `Property: ${key}`
		},
		getPropertyMinimum(key) {
			return undefined
		},
		getPropertyMaximum(key) {
			return undefined
		},
		getPropertyStep(key) {
			return undefined
		},
		getDisplayValue(key, value) {
			// If we have an edited value in formData, use that
			if (this.formData[key] !== undefined) {
				return this.formData[key]
			}
			// Otherwise use the original value
			return value
		},
		// Publish/Depublish methods
		openPublishModal() {
			if (this.currentObject['@self']?.published) {
				this.publishDate = new Date(this.currentObject['@self'].published)
			} else {
				this.publishDate = null
			}
			this.showPublishModal = true
		},
		openDepublishModal() {
			if (this.currentObject['@self']?.depublished) {
				this.depublishDate = new Date(this.currentObject['@self'].depublished)
			} else {
				this.depublishDate = null
			}
			this.showDepublishModal = true
		},
		closePublishModal() {
			this.showPublishModal = false
			this.publishDate = null
			this.isPublishing = false
		},
		closeDepublishModal() {
			this.showDepublishModal = false
			this.depublishDate = null
			this.isDepublishing = false
		},
		async publishObject() {
			this.isPublishing = true
			try {
				// Implement publish logic here
				console.log('Publishing object with date:', this.publishDate)
				this.closePublishModal()
				this.success = 'Object published successfully'
				setTimeout(() => {
					this.success = null
				}, 3000)
			} catch (error) {
				console.error('Failed to update object publication:', error)
				this.error = 'Failed to update object publication: ' + error.message
				setTimeout(() => {
					this.error = null
				}, 5000)
			} finally {
				this.isPublishing = false
			}
		},
		async depublishObject() {
			this.isDepublishing = true
			try {
				// Implement depublish logic here
				console.log('Depublishing object with date:', this.depublishDate)
				this.closeDepublishModal()
				this.success = 'Object depublished successfully'
				setTimeout(() => {
					this.success = null
				}, 3000)
			} catch (error) {
				console.error('Failed to update object depublication:', error)
				this.error = 'Failed to update object depublication: ' + error.message
				setTimeout(() => {
					this.error = null
				}, 5000)
			} finally {
				this.isDepublishing = false
			}
		},
	},
}
</script>

<style scoped>
/* ViewObject-specific overrides only */
.tableColumnActions {
	width: 100px;
	text-align: center;
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

.prop-cell-content {
	display: flex;
	align-items: center;
	gap: 8px;
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
}

.value-input-container {
	padding: 0;
	margin: 0;
	width: 100%;
}

.value-input-container .text-field {
	margin: 0;
	padding: 0;
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
</style> 