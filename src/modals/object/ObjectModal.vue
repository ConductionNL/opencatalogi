<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { getRequestToken } from '@nextcloud/auth'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

import '../../css/json-highlight.css'
</script>

<template>
	<div>
		<NcDialog
			id="objectModal"
			:name="dialogTitle"
			size="large"
			:canClose="false">
			<div class="dialog-content">
				<NcNoteCard v-if="success" type="success" class="note-card">
					<p>
						{{
							isNewObject
								? t(
										'opencatalogi',
										'Publication successfully created',
									)
								: t(
										'opencatalogi',
										'Publication successfully modified',
									)
						}}
					</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error" class="note-card">
					<p>{{ error }}</p>
				</NcNoteCard>

				<div v-if="!success">
					<!-- Register and Schema Info with card style -->
					<div class="detail-grid">
						<div
							class="detail-item"
							:class="{ 'empty-value': !selectedCatalogus }">
							<span class="detail-label">{{
								t('opencatalogi', 'Catalogus:')
							}}</span>
							<NcButton
								v-if="selectedCatalogus"
								class="pencil-button"
								:aria-label="t('opencatalogi', 'Change catalog')"
								@click="
									() => {
										selectedCatalogus = null
										selectedRegister = null
										selectedSchema = null
									}
								">
								<Pencil :size="20" />
							</NcButton>
							<div class="detail-value-container">
								<span
									v-if="selectedCatalogus"
									class="detail-value"
									>{{
										selectedCatalogus?.label
										|| t('opencatalogi', 'Not selected')
									}}</span
								>
								<span
									v-if="selectedCatalogus"
									class="sub-detail-value"
									>{{ selectedCatalogus?.id }}</span
								>
								<NcSelect
									v-if="!selectedCatalogus"
									v-model="selectedCatalogus"
									:options="catalogOptions"
									:aria-label-combobox="
										t('opencatalogi', 'Catalog')
									"
									labelOutside
									:disabled="objectStore.isLoading('object')"
									required
									:loading="loading" />
							</div>
						</div>
						<div
							class="detail-item"
							:class="{ 'empty-value': !selectedRegister }">
							<span class="detail-label">{{
								t('opencatalogi', 'Register:')
							}}</span>
							<NcButton
								v-if="selectedRegister"
								class="pencil-button"
								:aria-label="t('opencatalogi', 'Change register')"
								@click="
									() => {
										selectedRegister = null
										selectedSchema = null
									}
								">
								<Pencil :size="20" />
							</NcButton>
							<div class="detail-value-container">
								<span v-if="selectedRegister" class="detail-value">{{
									selectedRegister?.label
									|| t('opencatalogi', 'Not selected')
								}}</span>
								<span
									v-if="selectedRegister"
									class="sub-detail-value"
									>{{ selectedRegister?.id }}</span
								>
								<NcSelect
									v-if="!selectedRegister"
									v-model="selectedRegister"
									:options="registerOptions"
									:aria-label-combobox="
										t('opencatalogi', 'Register')
									"
									labelOutside
									:disabled="
										objectStore.isLoading('object')
										|| !selectedCatalogus
									"
									required
									:loading="loading" />
							</div>
						</div>
						<div
							class="detail-item"
							:class="{ 'empty-value': !selectedSchema }">
							<span class="detail-label">{{
								t('opencatalogi', 'Schema:')
							}}</span>
							<NcButton
								v-if="selectedSchema"
								class="pencil-button"
								:aria-label="t('opencatalogi', 'Change schema')"
								@click="
									() => {
										selectedSchema = null
									}
								">
								<Pencil :size="20" />
							</NcButton>
							<div class="detail-value-container">
								<span v-if="selectedSchema" class="detail-value">{{
									selectedSchema?.label
									|| t('opencatalogi', 'Not selected')
								}}</span>
								<span
									v-if="selectedSchema"
									class="sub-detail-value"
									>{{ selectedSchema?.id }}</span
								>
								<NcSelect
									v-if="!selectedSchema"
									v-model="selectedSchema"
									:options="schemaOptions"
									:aria-label-combobox="
										t('opencatalogi', 'Schema')
									"
									labelOutside
									:disabled="
										objectStore.isLoading('object')
										|| !selectedRegister
									"
									required
									:loading="loading" />
							</div>
						</div>
					</div>

					<!-- Upload Files Button (only for existing objects) -->
					<!-- <NcButton v-if="!isNewObject"
						type="secondary"
						class="upload-files-btn"
						@click="openUploadFilesModal">
						<template #icon>
							<Plus :size="20" />
						</template>
						Upload Files
					</NcButton> -->

					<!-- Edit Tabs -->
					<div class="tabContainer">
						<AppTabs v-model="activeTab" contentClass="mt-3" justified>
							<AppTab :title="t('opencatalogi', 'Form Editor')" active>
								<div v-if="fullSelectedSchema" class="form-editor">
									<div
										v-for="(prop, key) in schemaProperties"
										:key="key"
										class="form-field">
										<template v-if="prop.type === 'string'">
											<!-- v9 NcTextField takes `modelValue` / emits `update:modelValue`;
											     the old `value` / `update:value` pair no longer exists. -->
											<NcTextField
												:label="prop.title || key"
												:modelValue="getFieldValue(key)"
												:placeholder="prop.example"
												:helperText="prop.description"
												:required="prop.required"
												@update:modelValue="
													(value) =>
														setFieldValue(key, value)
												" />
										</template>
										<template
											v-else-if="prop.type === 'boolean'">
											<!-- v9 NcCheckboxRadioSwitch has no `checked` prop — it is
											     `modelValue`, so `:checked.sync` was doubly dead. -->
											<NcCheckboxRadioSwitch
												v-model="formData[key]"
												:helperText="prop.description"
												type="switch">
												{{ prop.title || key }}
											</NcCheckboxRadioSwitch>
										</template>
										<template
											v-else-if="
												prop.type === 'number'
												|| prop.type === 'integer'
											">
											<NcTextField
												:label="prop.title || key"
												:modelValue="getFieldValue(key)"
												:placeholder="prop.example"
												:helperText="prop.description"
												:required="prop.required"
												type="number"
												:min="prop.minimum"
												:max="prop.maximum"
												:step="
													prop.type === 'integer'
														? '1'
														: 'any'
												"
												@update:modelValue="
													(value) =>
														setFieldValue(key, value)
												" />
										</template>
										<template v-else>
											{{ prop.type }}
										</template>
									</div>
								</div>
								<NcEmptyContent v-else>
									{{
										t(
											'opencatalogi',
											'Please select a schema to edit the publication',
										)
									}}
								</NcEmptyContent>
							</AppTab>

							<AppTab :title="t('opencatalogi', 'JSON Editor')">
								<div class="json-editor">
									<div
										:class="`codeMirrorContainer ${getTheme()}`">
										<CodeMirror
											v-model="jsonData"
											:basic="true"
											:disabled="!fullSelectedSchema"
											placeholder='{ "key": "value" }'
											:dark="getTheme() === 'dark'"
											:linter="jsonParseLinter()"
											:lang="json()"
											:extensions="[json()]"
											:tabSize="2"
											style="height: 400px" />
										<NcButton
											class="format-json-button"
											variant="secondary"
											size="small"
											:disabled="!fullSelectedSchema"
											@click="formatJSON">
											{{ t('opencatalogi', 'Format JSON') }}
										</NcButton>
									</div>
									<span
										v-if="!isValidJson(jsonData)"
										class="error-message">
										{{
											t('opencatalogi', 'Invalid JSON format')
										}}
									</span>
								</div>
							</AppTab>
						</AppTabs>
					</div>
				</div>
			</div>

			<template #actions>
				<NcButton @click="closeModal">
					<template #icon>
						<Cancel :size="20" />
					</template>
					{{
						success
							? t('opencatalogi', 'Close')
							: t('opencatalogi', 'Cancel')
					}}
				</NcButton>

				<NcButton
					:disabled="
						loading || (activeTab === 1 && !isValidJson(jsonData))
					"
					variant="primary"
					@click="saveObject">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSaveOutline v-else-if="!isNewObject" :size="20" />
						<Plus v-else :size="20" />
					</template>
					{{
						isNewObject
							? t('opencatalogi', 'Add')
							: t('opencatalogi', 'Save')
					}}
				</NcButton>
			</template>
		</NcDialog>
		<!-- Add the UploadFiles modal for file uploads -->
		<!-- <UploadFiles /> -->
	</div>
</template>

<script>
import { json, jsonParseLinter } from '@codemirror/lang-json'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import Cancel from 'vue-material-design-icons/Cancel.vue'
// Icons
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import AppTab from '../../components/tabs/AppTab.vue'
import AppTabs from '../../components/tabs/AppTabs.vue'
import { getTheme } from '../../services/getTheme.js'
import { sortOptionsByLabel } from '../../services/sortOptionsByLabel.js'

/**
 * A select option of a catalog, register or schema.
 *
 * @param {{id: string|number, title: string}} entry - The entry
 * @return {{id: string|number, label: string}} The option
 */
function optionOf(entry) {
	return { id: entry.id, label: entry.title }
}

/**
 * Whether the navigation transfer data opens the modal to create an object.
 *
 * @param {*} transferData - The navigation transfer data
 * @return {boolean} True in create mode
 */
function isCreateTransfer(transferData) {
	return (
		transferData === 'ignore selectedCatalogus' || transferData === 'create'
	)
}

/**
 * The listed catalog of the active catalog, or null without one.
 *
 * @param {object|undefined} activeCatalog - The active catalog
 * @return {object|null|undefined} The listed catalog
 */
function listedCatalog(activeCatalog) {
	return activeCatalog
		? objectStore
				.getCollection('catalog')
				.results.find((catalog) => catalog.id === activeCatalog.id)
		: null
}

/**
 * Whether a list holds exactly one entry.
 *
 * @param {Array|undefined} list - The list
 * @return {boolean} True for a one-entry list
 */
function hasSingleEntry(list) {
	return Boolean(list && list.length === 1)
}

/**
 * Whether an active publication is a new object: missing, empty, or
 * without an id on itself or on `@self`.
 *
 * @param {object|null|undefined} activeObject - The active publication
 * @return {boolean} True when it is new
 */
function looksLikeNewObject(activeObject) {
	if (isMissingOrEmpty(activeObject)) {
		return true
	}
	// More robust detection of new vs existing objects
	return !activeObject || lacksIds(activeObject)
}

/**
 * Whether an active publication is missing or has no keys.
 *
 * @param {object|null|undefined} activeObject - The active publication
 * @return {boolean} True when missing or empty
 */
function isMissingOrEmpty(activeObject) {
	return (
		activeObject === null
		|| activeObject === undefined
		|| Object.keys(activeObject || {}).length === 0
	)
}

/**
 * Whether a publication has no id on `@self` and none on itself.
 *
 * @param {object} activeObject - The active publication
 * @return {boolean} True without ids
 */
function lacksIds(activeObject) {
	return !activeObject?.['@self']?.id && !activeObject?.id
}

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'ObjectModal',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		// Imported and used in the template but never registered before, so the
		// note cards, the loading spinner and every icon silently failed to
		// resolve. Pre-existing bug, not a migration artefact.
		NcLoadingIcon,
		NcNoteCard,
		AppTabs,
		AppTab,
		CodeMirror,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
		Pencil,
	},

	data() {
		return {
			activeTab: 0,
			isNewObject: false,
			loading: false,
			error: null,
			success: null,
			closeModalTimeout: null,

			selectedCatalogus: null,
			selectedRegister: null,
			selectedSchema: null,

			formData: {},
			jsonData: '',
		}
	},

	computed: {
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		catalogOptions() {
			return objectStore.getCollection('catalog').results.map((catalog) => ({
				id: catalog.id,
				label: catalog.title,
			}))
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		registerOptions() {
			if (!this.selectedCatalogus) {
				return []
			}

			const fullCatalog = objectStore
				.getCollection('catalog')
				.results.find((catalog) => catalog.id === this.selectedCatalogus.id)
			const selectedCatalogRegistersIds = fullCatalog.registers

			return objectStore.availableRegisters
				.filter((register) =>
					selectedCatalogRegistersIds.includes(register.id),
				)
				.map((register) => ({
					id: register.id,
					label: register.title,
				}))
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		schemaOptions() {
			if (!this.selectedRegister || !this.selectedCatalogus) {
				return []
			}

			const register = objectStore.availableRegisters.find(
				(register) => register.id === this.selectedRegister.id,
			)
			const catalog = objectStore
				.getCollection('catalog')
				.results.find((catalog) => catalog.id === this.selectedCatalogus.id)

			const validSchemaIds = sharedSchemaIds(register, catalog)

			return sortOptionsByLabel(
				objectStore.availableSchemas
					.filter((schema) => validSchemaIds.includes(schema.id))
					.map(toSchemaOption),
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		fullSelectedSchema() {
			return objectStore.availableSchemas.find(
				(schema) => schema.id === this.selectedSchema?.id,
			)
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		schemaProperties() {
			return this.fullSelectedSchema?.properties || {}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		dialogTitle() {
			return this.isNewObject ? 'Add Publication' : 'Edit Publication'
		},
	},

	watch: {
		objectStore: {
			/**
			 * @param newValue
			 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2
			 */
			handler(newValue) {
				if (newValue) {
					this.initializeData()
				}
			},

			deep: true,
		},

		'navigationStore.modal': {
			/**
			 * @param newValue
			 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2
			 */
			handler(newValue) {
				if (newValue === 'objectModal') {
					// Reinitialize when modal opens
					this.$nextTick(() => {
						// Give the store time to update
						setTimeout(() => {
							this.initializeData()
						}, 10)
					})
				}
			},
		},

		jsonData: {
			/**
			 * @param newValue
			 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2
			 */
			handler(newValue) {
				if (this.activeTab === 1 && this.isValidJson(newValue)) {
					this.updateFormFromJson()
				}
			},
		},

		formData: {
			/**
			 * @param newValue
			 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2
			 */
			handler(newValue) {
				if (this.activeTab === 0) {
					this.updateJsonFromForm()
				}
			},

			deep: true,
		},
	},

	mounted() {
		this.initializeData()
	},

	beforeUnmount() {
		clearTimeout(this.closeModalTimeout)
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		initializeData() {
			const activeCatalog = objectStore.getActiveObject('catalog')
			this.selectInitialCatalog(activeCatalog)

			const activeObject = objectStore.getActiveObject('publication')
			// Update activeCatalog reference in case we auto-selected one above
			const updatedActiveCatalog = objectStore.getActiveObject('catalog')

			// Check if we're in create mode based on navigation transfer data
			const transferData = navigationStore.getTransferData()
			const isCreateMode = isCreateTransfer(transferData)

			// Force new object detection in create mode, else detect it from the object
			this.isNewObject = isCreateMode || looksLikeNewObject(activeObject)

			this.logInitialState({
				activeObject,
				activeCatalog,
				updatedActiveCatalog,
				transferData,
				isCreateMode,
			})

			this.applyInitialObject(activeObject, updatedActiveCatalog)
		},

		/**
		 * Load an existing object into the form, or preselect the register
		 * and schema of a new one from the catalog.
		 *
		 * @param {object|null|undefined} activeObject - The active publication
		 * @param {object|undefined} catalog - The active catalog
		 * @return {void}
		 */
		applyInitialObject(activeObject, catalog) {
			if (!this.isNewObject) {
				this.loadExistingObject(activeObject)
			} else {
				this.preselectFromCatalog(catalog)
			}
		},

		/**
		 * Select the listed active catalog, else the only catalog there is,
		 * which then also becomes the active catalog.
		 *
		 * @param {object|undefined} activeCatalog - The active catalog
		 * @return {void}
		 */
		selectInitialCatalog(activeCatalog) {
			const catalogMatch = listedCatalog(activeCatalog)
			this.selectedCatalogus = catalogMatch ? optionOf(catalogMatch) : null

			// If no catalog is selected but there's only one available, auto-select it
			if (
				!this.selectedCatalogus
				&& objectStore.getCollection('catalog').results.length === 1
			) {
				const onlyCatalog = objectStore.getCollection('catalog').results[0]
				this.selectedCatalogus = optionOf(onlyCatalog)
				// Also set it as the active catalog in the store
				objectStore.setActiveObject('catalog', onlyCatalog)
			}
		},

		/**
		 * Temporary debug log of the initial state, on the local development
		 * host only.
		 *
		 * @param {object} state - The objects and transfer data initializeData read
		 * @return {void}
		 */
		logInitialState(state) {
			if (window.location.hostname === 'nextcloud.local') {
				// eslint-disable-next-line no-console
				console.log('ObjectModal debug:', {
					activeObject: state.activeObject,
					activeCatalog: state.activeCatalog,
					updatedActiveCatalog: state.updatedActiveCatalog,
					selectedCatalogus: this.selectedCatalogus,
					transferData: state.transferData,
					isCreateMode: state.isCreateMode,
					isNewObject: this.isNewObject,
					objectKeys: state.activeObject
						? Object.keys(state.activeObject)
						: 'null',
				})
			}
		},

		/**
		 * Load an existing object into the form, with its register and schema
		 * selected.
		 *
		 * @param {object} activeObject - The object
		 * @return {void}
		 */
		loadExistingObject(activeObject) {
			this.formData = structuredClone(activeObject)
			this.jsonData = JSON.stringify(activeObject, null, 2)

			// Set register and schema from existing object
			const register = objectStore.availableRegisters.find(
				(register) =>
					String(register.id) === String(activeObject['@self'].register),
			)
			this.selectedRegister = register && optionOf(register)

			const schema = objectStore.availableSchemas.find(
				(schema) => String(schema.id) === String(activeObject['@self'].schema),
			)
			this.selectedSchema = schema && optionOf(schema)
		},

		/**
		 * For a new object, preselect the register when the catalog has only
		 * one, and then the schema when it has only one.
		 *
		 * @param {object|undefined} catalog - The active catalog
		 * @return {void}
		 */
		preselectFromCatalog(catalog) {
			if (!catalog || !hasSingleEntry(catalog.registers)) {
				return
			}

			const register = objectStore.availableRegisters.find(
				(register) => register.id === catalog.registers[0],
			)
			this.selectedRegister = register && optionOf(register)

			if (hasSingleEntry(catalog.schemas)) {
				const schema = objectStore.availableSchemas.find(
					(schema) => schema.id === catalog.schemas[0],
				)
				this.selectedSchema = schema && optionOf(schema)
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		async saveObject() {
			if (!this.selectedRegister || !this.selectedSchema) {
				this.error = 'Register and schema are required'
				return
			}

			this.loading = true
			this.error = null

			const method = this.isNewObject ? 'POST' : 'PUT'
			const BASE_URL = `/index.php/apps/openregister/api/objects/${this.selectedRegister.id}/${this.selectedSchema.id}`

			let FETCH_URL = BASE_URL
			if (!this.isNewObject) {
				const activeObject = objectStore.getActiveObject('publication')
				const objectId = activeObject?.['@self']?.id || activeObject?.id
				if (!objectId) {
					throw new Error('Cannot update object: no object ID found')
				}
				FETCH_URL = `${BASE_URL}/${objectId}`
			}
			try {
				let dataToSave
				if (this.activeTab === 1) {
					if (!this.jsonData.trim()) {
						throw new Error('JSON data cannot be empty')
					}
					try {
						dataToSave = JSON.parse(this.jsonData)
					} catch (e) {
						throw new Error('Invalid JSON format: ' + e.message, {
							cause: e,
						})
					}
				} else {
					dataToSave = this.formData
				}

				const response = await fetch(FETCH_URL, {
					method,
					body: JSON.stringify(dataToSave),
					headers: {
						requesttoken: getRequestToken(),
						'Content-Type': 'application/json',
					},
				})

				if (response.ok) {
					const responseData = await response.json()
					this.success = true

					if (responseData) {
						const publicationId =
							responseData.id || responseData['@self']?.id || null
						const publicationData = {
							...responseData,
							id: publicationId,
						}

						// Set the active object directly in the store without related data fetch
						// This avoids the configuration error since publications might not be properly registered yet
						objectStore.activeObjects = {
							...objectStore.activeObjects,
							publication: publicationData,
						}

						// Initialize related data structure without fetching
						objectStore.relatedData = {
							...objectStore.relatedData,
							publication: {
								logs: null,
								uses: null,
								used: null,
								files: null,
							},
						}

						this.closeModalTimeout = setTimeout(this.closeModal, 2000)
						catalogStore.refreshPublications()
						catalogStore.fetchPublications()
					}
				} else {
					this.success = false
					const errorData = await response
						.json()
						.catch(() => ({ message: 'Unknown error' }))
					this.error = errorData.message || 'Failed to save object'
				}
			} catch (e) {
				this.error = e.message || 'Failed to save object'
				this.success = false
			} finally {
				this.loading = false
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		updateFormFromJson() {
			try {
				const parsed = JSON.parse(this.jsonData)
				this.formData = parsed
			} catch (e) {
				this.error = 'Invalid JSON format'
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		updateJsonFromForm() {
			try {
				this.jsonData = JSON.stringify(this.formData, null, 2)
			} catch (e) {
				console.error('Error updating JSON:', e)
			}
		},

		/**
		 * @param str
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2
		 */
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

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
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

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = null
			this.formData = {}
			this.jsonData = ''
			this.activeTab = 0
			// Reset selections
			this.selectedCatalogus = null
			this.selectedRegister = null
			this.selectedSchema = null
			this.isNewObject = false
		},

		getFieldValue(key) {
			return this.formData[key] ?? ''
		},

		/**
		 * @param key
		 * @param value
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2
		 */
		setFieldValue(key, value) {
			if (this.formData[key] === value) return
			this.formData[key] = value
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-2 */
		openUploadFilesModal() {
			// Set the navigationStore modal to 'uploadFiles' to show the UploadFiles modal
			navigationStore.setModal('uploadFiles')
		},
	},
}

/**
 * The ids of the schemas a register and a catalog both have.
 *
 * @param {{schemas: Array<object>}} register The register, with schema objects.
 * @param {{schemas: Array<number|string>}} catalog The catalog, with schema ids.
 * @return {Array<number|string>} The shared schema ids, in register order.
 */
function sharedSchemaIds(register, catalog) {
	const registerSchemaIds = register.schemas.map((schema) => schema.id)
	const catalogSchemaIds = catalog.schemas

	// only get schema ids where the id is in both registerSchemaIds and catalogSchemaIds
	return registerSchemaIds.filter((id) => catalogSchemaIds.includes(id))
}

/**
 * A schema as an option of the schema dropdown.
 *
 * @param {object} schema The schema.
 * @return {{id: (number|string), label: string}} The option.
 */
function toSchemaOption(schema) {
	return {
		id: schema.id,
		label: schema.title,
	}
}
</script>

<style scoped>
:deep(.modal-container) {
	width: 937px !important;
}

/* Add consistent dialog content spacing */
.dialog-content {
	padding: 0 20px;
}

/* Update note card margins */
:deep(.note-card) {
	margin: 20px 0;
}

/* Update detail grid margins */
.detail-grid {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 12px;
	margin: 20px 0;
	max-width: 100%;
}

.detail-item {
	display: grid;
	grid-template-columns: 1fr auto;
	grid-template-areas:
		'label button'
		'value value';
	gap: 8px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
	border-inline-start: 3px solid var(--color-primary);
}

.detail-label {
	grid-area: label;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.pencil-button {
	grid-area: button;
}

.detail-value-container {
	grid-area: value;
	display: flex;
	flex-direction: column;
}

.detail-value {
	overflow-wrap: break-word;
}

.sub-detail-value {
	overflow-wrap: break-word;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.detail-item.empty-value {
	border-inline-start-color: var(--color-warning);
}

.edit-tabs {
	margin-top: 20px;
}

.form-field {
	margin-bottom: 16px;
}

/* JSON Editor styles */
.json-editor {
	position: relative;
	margin-bottom: 2.5rem;
}

.codeMirrorContainer {
	margin-block-start: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	position: relative;
}

.codeMirrorContainer :deep(.cm-editor) {
	height: 100%;
	outline: none !important;
}

.codeMirrorContainer :deep(.cm-scroller) {
	overflow: auto;
}

.format-json-button {
	position: absolute;
	bottom: 0;
	inset-inline-end: 0;
	transform: translateY(100%);
}

.error-message {
	position: absolute;
	bottom: 0;
	inset-inline-end: 50%;
	transform: translateY(100%) translateX(50%);
	color: var(--color-error);
	font-size: 0.8rem;
	padding-top: 0.25rem;
}

/* Dark mode specific styles */
.codeMirrorContainer.dark :deep(.cm-editor) {
	background-color: var(--color-background-darker);
}

.codeMirrorContainer.light :deep(.cm-editor) {
	background-color: var(--color-background-hover);
}

/* Add tab container styles */
.tabContainer {
	margin-top: 20px;
}

/* Style the tabs to match ViewObject */
:deep(.nav-tabs) {
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 15px;
}

:deep(.nav-tabs .nav-link) {
	border: none;
	border-bottom: 2px solid transparent;
	color: var(--color-text-maxcontrast);
	padding: 8px 16px;
}

:deep(.nav-tabs .nav-link.active) {
	color: var(--color-main-text);
	border-bottom: 2px solid var(--color-primary);
	background-color: transparent;
}

:deep(.nav-tabs .nav-link:hover) {
	border-bottom: 2px solid var(--color-border);
}

:deep(.tab-content) {
	padding: 16px;
	background-color: var(--color-main-background);
}

/* Form editor specific styles */
.form-editor {
	display: flex;
	flex-direction: column;
	padding: 16px;
}

/* CodeMirror JSON token/selection colors — see src/css/json-highlight.css (imported in <script setup>). */
</style>
