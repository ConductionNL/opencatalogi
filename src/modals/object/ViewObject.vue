/**
 * @file ViewObject.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<div>
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
					<div v-else-if="isNewObject && (hasSelectedSchema || allSelectionsComplete)" class="viewTableContainer">
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
										<div class="value-cell-content">
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
												<NcDateTimePicker
													v-else-if="getPropertyInputComponent(key) === 'NcDateTimePicker'"
													:value="getDateTimePickerValue(key, value)"
													:label="getPropertyDisplayName(key)"
													:type="getDateTimePickerType(key)"
													:placeholder="getPropertyDisplayName(key)"
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
														v-else-if="isDateTimeProperty(key) && formData[key]"
														v-tooltip="`${getDateTimePropertyFormat(key)}: ${formData[key]} (edited)`">{{ formatDateTimeValue(key, formData[key]) }}</span>
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
														v-else-if="isDateTimeProperty(key) && value"
														v-tooltip="`${getDateTimePropertyFormat(key)}: ${value}`">{{ formatDateTimeValue(key, value) }}</span>
													<span
														v-else-if="isValidDate(value)"
														v-tooltip="`Date: ${new Date(value).toISOString()}`">{{ new Date(value).toLocaleString() }}</span>
													<span
														v-else
														v-tooltip="getPropertyTooltip(key)">{{ getDisplayValue(key, value) }}</span>
												</template>
											</div>
											<NcButton v-if="canDropProperty(key, value)"
												v-tooltip="getDropPropertyTooltip(key)"
												type="tertiary-no-background"
												size="small"
												class="drop-property-btn"
												:aria-label="getDropPropertyTooltip(key)"
												@click.stop="dropProperty(key)">
												<template #icon>
													<Close :size="16" />
												</template>
											</NcButton>
										</div>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- For existing objects, show tabs -->
					<div v-else class="tabContainer">
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
													<div class="value-cell-content">
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
															<NcDateTimePicker
																v-else-if="getPropertyInputComponent(key) === 'NcDateTimePicker'"
																:value="getDateTimePickerValue(key, value)"
																:label="getPropertyDisplayName(key)"
																:type="getDateTimePickerType(key)"
																:placeholder="getPropertyDisplayName(key)"
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
																	v-else-if="isDateTimeProperty(key) && formData[key]"
																	v-tooltip="`${getDateTimePropertyFormat(key)}: ${formData[key]} (edited)`">{{ formatDateTimeValue(key, formData[key]) }}</span>
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
																	v-else-if="isDateTimeProperty(key) && value"
																	v-tooltip="`${getDateTimePropertyFormat(key)}: ${value}`">{{ formatDateTimeValue(key, value) }}</span>
																<span
																	v-else-if="isValidDate(value)"
																	v-tooltip="`Date: ${new Date(value).toISOString()}`">{{ new Date(value).toLocaleString() }}</span>
																<span
																	v-else
																	v-tooltip="getPropertyTooltip(key)">{{ getDisplayValue(key, value) }}</span>
															</template>
														</div>
														<NcButton v-if="canDropProperty(key, value)"
															v-tooltip="getDropPropertyTooltip(key)"
															type="tertiary-no-background"
															size="small"
															class="drop-property-btn"
															:aria-label="getDropPropertyTooltip(key)"
															@click.stop="dropProperty(key)">
															<template #icon>
																<Close :size="16" />
															</template>
														</NcButton>
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
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="([key, value]) in metadataProperties"
												:key="key"
												class="viewTableRow">
												<td class="tableColumnConstrained">
													{{ key }}
												</td>
												<td class="tableColumnExpanded">
													{{ value }}
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</BTab>
							<BTab title="Files">
								<!-- Info box for new objects -->
								<NcNoteCard v-if="isNewObject" type="info" class="files-info-card">
									<p><strong>Files can be added after the publication is created.</strong></p>
									<p>Save the publication first, then you'll be able to upload and manage files.</p>
								</NcNoteCard>

								<div v-else-if="paginatedFiles.length > 0" class="viewTableContainer">
									<table class="viewTable">
										<thead>
											<tr class="viewTableRow">
												<th class="tableColumnCheckbox">
													<NcCheckboxRadioSwitch
														:checked="allFilesSelected"
														:indeterminate="someFilesSelected"
														@update:checked="toggleSelectAllFiles" />
												</th>
												<th class="tableColumnExpanded">
													Name
												</th>
												<th class="tableColumnConstrained">
													Size
												</th>
												<th class="tableColumnConstrained">
													Type
												</th>
												<th class="tableColumnConstrained">
													Labels
												</th>
												<th class="tableColumnActions">
													<NcActions
														:force-name="true"
														:disabled="selectedAttachments.length === 0"
														:title="selectedAttachments.length === 0 ? 'Select one or more files to use mass actions' : `Mass actions (${selectedAttachments.length} selected)`"
														:menu-name="`Mass Actions (${selectedAttachments.length})`">
														<template #icon>
															<FormatListChecks :size="20" />
														</template>
														<NcActionButton
															:disabled="publishLoading.length > 0 || selectedAttachments.length === 0"
															@click="publishSelectedFiles">
															<template #icon>
																<NcLoadingIcon v-if="publishLoading.length > 0" :size="20" />
																<FileOutline v-else :size="20" />
															</template>
															Publish {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
														</NcActionButton>
														<NcActionButton
															:disabled="depublishLoading.length > 0 || selectedAttachments.length === 0"
															@click="depublishSelectedFiles">
															<template #icon>
																<NcLoadingIcon v-if="depublishLoading.length > 0" :size="20" />
																<LockOutline v-else :size="20" />
															</template>
															Depublish {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
														</NcActionButton>
														<NcActionButton
															:disabled="fileIdsLoading.length > 0 || selectedAttachments.length === 0"
															@click="deleteSelectedFiles">
															<template #icon>
																<NcLoadingIcon v-if="fileIdsLoading.length > 0" :size="20" />
																<Delete v-else :size="20" />
															</template>
															Delete {{ selectedAttachments.length }} file{{ selectedAttachments.length > 1 ? 's' : '' }}
														</NcActionButton>
													</NcActions>
												</th>
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
														:checked="selectedAttachments.includes(attachment.id)"
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
														<span class="file-name">{{ truncateFileName(attachment.name ?? attachment?.title) }}</span>
													</div>
												</td>
												<td class="tableColumnConstrained">
													{{ formatFileSize(attachment?.size) }}
												</td>
												<td class="tableColumnConstrained">
													{{ attachment?.type || 'No type' }}
												</td>
												<td class="tableColumnConstrained">
													<div class="fileLabelsContainer">
														<NcCounterBubble v-for="label of attachment.labels" :key="label">
															{{ label }}
														</NcCounterBubble>
													</div>
												</td>
												<td class="tableColumnActions">
													<NcActions :aria-label="`Actions for ${attachment.name ?? attachment?.title ?? 'file'}`">
														<NcActionButton @click="openFile(attachment)">
															<template #icon>
																<OpenInNew :size="20" />
															</template>
															View
														</NcActionButton>
														<NcActionButton @click="editFileLabels(attachment)">
															<template #icon>
																<Tag :size="20" />
															</template>
															Labels
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
								<NcEmptyContent v-else-if="!isNewObject"
									name="No files attached"
									description="No files have been attached to this object">
									<template #icon>
										<FileOutline :size="64" />
									</template>
								</NcEmptyContent>

								<!-- Files Pagination -->
								<PaginationComponent
									v-if="currentObject?.['@self']?.files?.length > filesPerPage"
									:current-page="filesCurrentPage"
									:total-pages="filesTotalPages"
									:total-items="currentObject?.['@self']?.files?.length || 0"
									:current-page-size="filesPerPage"
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
	</div>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcActions,
	NcActionButton,
	NcNoteCard,
	NcCounterBubble,
	NcTextField,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcDateTimePicker,
	NcEmptyContent,
	NcSelect,
} from '@nextcloud/vue'
// import { json, jsonParseLinter } from '@codemirror/lang-json'
// import CodeMirror from 'vue-codemirror6'
import { BTabs, BTab } from 'bootstrap-vue'
import { getTheme } from '../../services/getTheme.js'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ExclamationThick from 'vue-material-design-icons/ExclamationThick.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import Close from 'vue-material-design-icons/Close.vue'
import PaginationComponent from '../../components/PaginationComponent.vue'
import PublishedIcon from '../../components/PublishedIcon.vue'

export default {
	name: 'ViewObject',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcCounterBubble,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcDateTimePicker,
		NcEmptyContent,
		NcSelect,
		// CodeMirror,
		BTabs,
		BTab,
		Cancel,
		FileOutline,
		OpenInNew,
		Delete,
		Upload,

		ContentSave,
		LockOutline,
		Tag,
		FormatListChecks,
		Alert,
		AlertCircle,
		Plus,
		Publish,
		PublishOff,
		Pencil,
		ExclamationThick,
		ArrowRight,
		Close,
		PaginationComponent,
		PublishedIcon,
	},
	data() {
		return {
			activeTab: 0,
			formData: {}, // Ensure this is always an object, never an array
			jsonData: '',
			selectedProperty: null,
			isSaving: false,
			success: null,
			error: null,
			isCopied: false,

			// Files tab properties
			activeAttachment: null,
			selectedAttachments: [],
			publishLoading: [],
			depublishLoading: [],
			fileIdsLoading: [],
			filesCurrentPage: 1,
			filesPerPage: 10,
			// Selection flow properties
			selectedCatalog: null,
			selectedRegister: null,
			selectedSchema: null,
			showProperties: false,
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
		objectProperties() {
			// Return array of [key, value] pairs, excluding '@self' and 'id'
			const objectData = this.currentObject || {}
			const schemaProperties = this.getSchemaProperties()

			const properties = []

			// First, add all schema properties in their defined order
			for (const [schemaKey, schemaProperty] of Object.entries(schemaProperties)) {
				if (Object.prototype.hasOwnProperty.call(objectData, schemaKey)) {
					// Property exists in object, use its value
					properties.push([schemaKey, objectData[schemaKey]])
				} else {
					// Property doesn't exist in object, use appropriate default value
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
					properties.push([schemaKey, defaultValue])
				}
			}

			// Then, add any additional properties from the object that aren't in the schema
			const additionalProperties = []
			for (const [objectKey, objectValue] of Object.entries(objectData)) {
				// Skip metadata and properties already handled by schema
				// Also skip properties that have been marked for deletion (undefined in formData)
				if (objectKey !== '@self'
					&& objectKey !== 'id'
					&& !Object.prototype.hasOwnProperty.call(schemaProperties, objectKey)
					&& !(this.formData[objectKey] === undefined)) {
					additionalProperties.push([objectKey, objectValue])
				}
			}

			// Sort additional properties alphabetically for consistency
			additionalProperties.sort(([keyA], [keyB]) => keyA.localeCompare(keyB))

			// If we have no properties to show (new object with no schema), provide some basic ones
			if (properties.length === 0 && additionalProperties.length === 0) {
				return [
					['title', ''],
					['description', ''],
					['summary', ''],
					['category', ''],
					['status', 'draft'],
				]
			}

			// Combine schema properties first, then additional properties
			return [...properties, ...additionalProperties]
		},
		metadataProperties() {
			// Return array of [key, value] for metadata display
			if (!this.currentObject) return []

			const obj = this.currentObject
			const metadata = []

			// ID
			metadata.push([
				'ID',
				obj.id || 'Not set',
			])

			// Version
			metadata.push([
				'Version',
				obj['@self']?.version || 'Not set',
			])

			// Register
			const register = obj['@self']?.register
			let registerDisplay = 'Not set'
			if (register) {
				if (typeof register === 'object') {
					registerDisplay = register.title || register.name || register.id || register
				} else {
					// Try to find the register title from available registers
					const availableRegister = objectStore.availableRegisters.find(r => r.id === register)
					registerDisplay = availableRegister?.title || register
				}
			}
			metadata.push([
				'Register',
				registerDisplay,
			])

			// Schema
			const schema = obj['@self']?.schema
			let schemaDisplay = 'Not set'
			if (schema) {
				if (typeof schema === 'object') {
					schemaDisplay = schema.title || schema.name || schema.id || schema
				} else {
					// Try to find the schema title from available schemas
					const availableSchema = objectStore.availableSchemas.find(s => s.id === schema)
					schemaDisplay = availableSchema?.title || schema
				}
			}
			metadata.push([
				'Schema',
				schemaDisplay,
			])

			// Locked
			const locked = obj['@self']?.locked
			let lockedDisplay = 'Not locked'
			if (locked) {
				if (typeof locked === 'object') {
					const lockedBy = locked.lockedBy || 'Unknown user'
					const lockedAt = locked.lockedAt ? new Date(locked.lockedAt).toLocaleString() : 'Unknown time'
					const process = locked.process ? ` (${locked.process})` : ''
					lockedDisplay = `Locked by ${lockedBy} at ${lockedAt}${process}`
				} else {
					lockedDisplay = 'Locked'
				}
			}
			metadata.push([
				'Locked',
				lockedDisplay,
			])

			// Created
			metadata.push([
				'Created',
				obj['@self']?.created ? new Date(obj['@self'].created).toLocaleString() : 'Not set',
			])

			// Updated
			metadata.push([
				'Updated',
				obj['@self']?.updated ? new Date(obj['@self'].updated).toLocaleString() : 'Not set',
			])

			// Published
			metadata.push([
				'Published',
				obj['@self']?.published ? new Date(obj['@self'].published).toLocaleString() : 'Not published',
			])

			// Depublished
			metadata.push([
				'Depublished',
				obj['@self']?.depublished ? new Date(obj['@self'].depublished).toLocaleString() : 'Not depublished',
			])

			return metadata
		},
		// Files tab computed properties
		paginatedFiles() {
			const filesData = objectStore.getRelatedData('publication', 'files')
			const files = filesData?.results || []
			// Ensure files is an array before calling slice
			if (!Array.isArray(files)) {
				console.warn('Files data is not an array:', files)
				return []
			}
			const start = (this.filesCurrentPage - 1) * this.filesPerPage
			const end = start + this.filesPerPage
			return files.slice(start, end)
		},
		filesTotalPages() {
			const filesData = objectStore.getRelatedData('publication', 'files')
			const files = filesData?.results || []
			const totalFiles = Array.isArray(files) ? files.length : 0
			return Math.ceil(totalFiles / this.filesPerPage)
		},
		allFilesSelected() {
			return this.paginatedFiles.length > 0 && this.paginatedFiles.every(file => this.selectedAttachments.includes(file.id))
		},
		someFilesSelected() {
			return this.selectedAttachments.length > 0 && !this.allFilesSelected
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
	},
	methods: {
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
			this.success = null
			this.error = null
			this.isCopied = false
			this.selectedProperty = null

			// Clear Files tab state
			this.activeAttachment = null
			this.selectedAttachments = []
			this.publishLoading = []
			this.depublishLoading = []
			this.fileIdsLoading = []
			this.filesCurrentPage = 1

			// Clear selection flow state
			this.selectedCatalog = null
			this.selectedRegister = null
			this.selectedSchema = null
			this.showProperties = false

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
		initializeData() {
			if (!this.currentObject) {
				// For new objects, initialize with empty form data and auto-select if possible
				this.formData = {}
				this.jsonData = JSON.stringify({}, null, 2)

				// Auto-select catalog if there's only one
				const catalogs = objectStore.getCollection('catalog').results
				if (catalogs.length === 1) {
					this.selectedCatalog = {
						id: catalogs[0].id,
						label: catalogs[0].title,
					}

					// Use nextTick to ensure the computed properties are updated
					this.$nextTick(() => {
						// Auto-select register if there's only one
						if (this.registerOptions.length === 1) {
							this.selectedRegister = this.registerOptions[0]

							this.$nextTick(() => {
								// Auto-select schema if there's only one
								if (this.schemaOptions.length === 1) {
									this.selectedSchema = this.schemaOptions[0]
								}
							})
						}
					})
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
			this.jsonData = JSON.stringify(filtered, null, 2)
		},
		async saveObject() {
			this.isSaving = true
			this.error = null

			try {
				const isCreating = this.isNewObject

				// For new objects, validate we have the required selections
				if (isCreating && (!this.selectedSchema || !this.selectedRegister || !this.selectedCatalog)) {
					this.error = 'Please select catalog, register, and schema before saving'
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

				// Set the newly created/updated object as active in the object store
				objectStore.setActiveObject('publication', result)

				// Clear form data since we now have the saved object
				this.formData = {}

				this.success = `Publication ${isCreating ? 'created' : 'updated'} successfully`

				// Refresh the publications list
				catalogStore.fetchPublications()

				// Close modal for edit mode, keep open for create mode (which transitions to edit mode)
				if (!isCreating) {
					// For existing objects (edit mode), close the modal after save
					setTimeout(() => {
						this.closeModal()
					}, 1000) // Give time for success message to show
				}

				setTimeout(() => {
					this.success = null
				}, 3000)

			} catch (e) {
				console.error('Save error:', e)
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
			if (!value || typeof value !== 'string') return false

			// Don't treat simple strings like "test 12" as dates
			if (value.length < 8) return false

			// Check if it looks like a date format
			const datePatterns = [
				/^\d{4}-\d{2}-\d{2}/, // YYYY-MM-DD
				/^\d{1,2}-\d{1,2}-\d{4}/, // M-D-YYYY or MM-DD-YYYY
				/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/, // ISO datetime
			]

			const looksLikeDate = datePatterns.some(pattern => pattern.test(value))
			if (!looksLikeDate) return false

			// Try to parse it
			const date = new Date(value)
			return date instanceof Date && !isNaN(date) && date.getFullYear() > 1900
		},
		isDateTimeProperty(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			return schemaProperty && schemaProperty.type === 'string' && ['date', 'time', 'date-time'].includes(schemaProperty.format)
		},
		getDateTimePropertyFormat(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			return schemaProperty?.format || 'unknown'
		},
		formatDateTimeValue(key, value) {
			if (!value) return ''

			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			const format = schemaProperty?.format

			try {
				switch (format) {
				case 'date':
					// For date-only, show as date without time
					if (typeof value === 'string' && value.match(/^\d{4}-\d{2}-\d{2}$/)) {
						return new Date(value + 'T12:00:00').toLocaleDateString()
					}
					return new Date(value).toLocaleDateString()
				case 'time':
					// For time-only, show just the time part
					if (typeof value === 'string' && value.match(/^\d{2}:\d{2}(:\d{2})?$/)) {
						return value
					}
					return new Date(value).toLocaleTimeString()
				case 'date-time':
					// For date-time, show full date and time
					return new Date(value).toLocaleString()
				default:
					return value
				}
			} catch (e) {
				return value
			}
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
				// console.error('Failed to copy text:', err)
			}
		},
		// Property validation and editing methods
		getPropertyValidationClass(key, value) {
			// Skip @self as it's metadata
			if (key === '@self') {
				return ''
			}

			// Get schema properties
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			const existsInObject = this.currentObject && Object.prototype.hasOwnProperty.call(this.currentObject, key)

			if (!schemaProperty) {
				// Property exists in object but not in schema - warning (yellow)
				if (existsInObject) {
					return 'property-warning'
				}
				return ''
			}

			if (!existsInObject) {
				// Property exists in schema but not in object yet - neutral (no special class)
				return 'property-new'
			}

			// Property exists in both schema and object, validate the value
			if (this.isValidPropertyValue(key, value, schemaProperty)) {
				// Valid property - success (green)
				return 'property-valid'
			} else {
				// Invalid property - error (red)
				return 'property-invalid'
			}
		},
		getPropertyErrorMessage(key, value) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			if (!schemaProperty) {
				return `Property '${key}' is not defined in the current schema. This property exists in the object but is not part of the schema definition.`
			}

			// Check if required but empty
			const isRequired = schemaProperty.required
			if ((value === null || value === undefined || value === '') && isRequired) {
				return `Required property '${key}' is missing or empty`
			}

			// Check type mismatch
			const expectedType = schemaProperty.type
			const actualType = Array.isArray(value) ? 'array' : typeof value

			if (expectedType !== actualType) {
				return `Property '${key}' should be ${expectedType} but is ${actualType}`
			}

			// Check format constraints
			if (schemaProperty.format === 'date-time' && !this.isValidDate(value)) {
				return `Property '${key}' should be a valid date-time format`
			}

			// Check const constraint
			if (schemaProperty.const && value !== schemaProperty.const) {
				return `Property '${key}' should be '${schemaProperty.const}' but is '${value}'`
			}

			return `Property '${key}' has an invalid value`
		},
		getPropertyWarningMessage(key, value) {
			return `Property '${key}' exists in the object but is not defined in the current schema. This might happen when property names are changed in the schema.`
		},
		getPropertyNewMessage(key) {
			return `Property '${key}' is defined in the schema but doesn't have a value yet. Click to add a value.`
		},
		isPropertyEditable(key, value) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			// If no schema property, allow editing (it's a free-form property)
			if (!schemaProperty) return true

			// Check if property is const
			if (schemaProperty.const !== undefined) {
				return false // Const properties cannot be edited
			}

			// Check if property is immutable and already has a value
			if (schemaProperty.immutable && (value !== null && value !== undefined && value !== '')) {
				return false // Immutable properties with values cannot be edited
			}

			return true
		},
		getEditabilityWarning(key, value) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			if (schemaProperty?.const !== undefined) {
				return `This property is constant and must always be '${schemaProperty.const}'. Const properties cannot be modified to maintain data integrity.`
			}

			if (schemaProperty?.immutable && (value !== null && value !== undefined && value !== '')) {
				return `This property is immutable and cannot be changed once it has a value. Current value: '${value}'. Immutable properties preserve data consistency.`
			}

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
			// Ensure formData is an object before updating
			if (!this.formData || Array.isArray(this.formData)) {
				this.formData = {}
			}

			// Convert date/time values to proper format for storage
			const processedValue = this.processDateTimeValue(key, newValue)

			// Update the form data using Vue 2 reactivity
			this.$set(this.formData, key, processedValue)
		},
		processDateTimeValue(key, value) {
			// Get schema information to determine if this is a date/time field
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			if (!schemaProperty || schemaProperty.type !== 'string') {
				return value
			}

			const format = schemaProperty.format
			if (!format || !['date', 'time', 'date-time'].includes(format)) {
				return value
			}

			// If value is empty or null, return empty string
			if (!value || value === '') {
				return ''
			}

			// Handle Date objects from NcDateTimePicker
			if (value instanceof Date) {
				try {
					switch (format) {
					case 'date':
						// Return YYYY-MM-DD format
						return value.toISOString().split('T')[0]
					case 'time':
						// Return HH:MM:SS format
						return value.toTimeString().split(' ')[0]
					case 'date-time':
						// Return full ISO string
						return value.toISOString()
					default:
						return value.toISOString()
					}
				} catch (e) {
					return ''
				}
			}

			// Handle string values (legacy or fallback)
			try {
				switch (format) {
				case 'date':
					// HTML date input returns YYYY-MM-DD, which is correct for JSON Schema date format
					return value
				case 'time':
					// HTML time input returns HH:MM, convert to full time format if needed
					// For time format, we might want to store as HH:MM:SS or keep as HH:MM
					return value.length === 5 ? `${value}:00` : value
				case 'date-time': {
					// HTML datetime-local input returns YYYY-MM-DDTHH:MM
					// Convert to full ISO string if needed
					if (value.length === 16) {
						// Add seconds and timezone
						return `${value}:00.000Z`
					}
					// If it's already a full ISO string, return as-is
					return value
				}
				default:
					return value
				}
			} catch (e) {
				return value
			}
		},
		getPropertyInputType(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			if (!schemaProperty) return 'text'

			const type = schemaProperty.type
			const format = schemaProperty.format

			// Handle different types and formats
			switch (type) {
			case 'string':
				if (format === 'date') return 'date'
				if (format === 'time') return 'time'
				if (format === 'date-time') return 'datetime-local'
				if (format === 'email') return 'email'
				if (format === 'url' || format === 'uri') return 'url'
				if (format === 'password') return 'password'
				return 'text'
			case 'number':
			case 'integer':
				return 'number'
			case 'boolean':
				return 'checkbox'
			default:
				return 'text'
			}
		},
		getPropertyInputComponent(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			if (!schemaProperty) return 'NcTextField'

			const type = schemaProperty.type
			const format = schemaProperty.format

			// Handle different types and formats
			switch (type) {
			case 'boolean':
				return 'NcCheckboxRadioSwitch'
			case 'string':
				if (format === 'date' || format === 'date-time') {
					return 'NcDateTimePicker'
				}
				if (format === 'time') {
					return 'NcTextField' // Use text field with time input type for time-only
				}
				return 'NcTextField'
			case 'number':
			case 'integer':
				return 'NcTextField'
			default:
				return 'NcTextField'
			}
		},
		getPropertyDisplayName(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			return schemaProperty?.title || key
		},
		getPropertyTooltip(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			if (schemaProperty?.description) {
				// If we have both title and description, show both
				if (schemaProperty.title && schemaProperty.title !== key) {
					return `${schemaProperty.title}: ${schemaProperty.description}`
				}
				// If only description or title same as key, just show description
				return schemaProperty.description
			}

			// Fallback to property key info
			return `Property: ${key}`
		},
		getPropertyMinimum(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			return schemaProperty?.minimum
		},
		getPropertyMaximum(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			return schemaProperty?.maximum
		},
		getPropertyStep(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			if (schemaProperty?.type === 'integer') {
				return '1'
			}
			if (schemaProperty?.type === 'number') {
				return 'any'
			}
			return undefined
		},
		getDisplayValue(key, value) {
			// If we have an edited value in formData, use that
			if (this.formData[key] !== undefined) {
				const editedValue = this.formData[key]
				// Handle date formatting for edited values
				if (this.isValidDate(editedValue) && typeof editedValue === 'string' && editedValue.includes('T')) {
					return new Date(editedValue).toLocaleString()
				}
				return editedValue
			}

			// Handle original value
			if (value === null || value === undefined) {
				return ''
			}

			// Handle date formatting for original values - only if it's actually a date string
			if (this.isValidDate(value) && typeof value === 'string' && (value.includes('T') || value.includes('-'))) {
				// Check if it looks like a date (has date separators)
				const datePattern = /^\d{4}-\d{2}-\d{2}|^\d{1,2}-\d{1,2}-\d{4}/
				if (datePattern.test(value)) {
					return new Date(value).toLocaleString()
				}
			}

			// For arrays and objects, format them nicely
			if (Array.isArray(value)) {
				return JSON.stringify(value)
			}
			if (typeof value === 'object' && value !== null) {
				return JSON.stringify(value)
			}

			// Return the value as-is for everything else
			return value
		},
		getDateTimeValue(key, value) {
			// Get the current value (either from formData or original value)
			const currentValue = this.formData[key] !== undefined ? this.formData[key] : value

			if (!currentValue) {
				return ''
			}

			// Get the input type to determine the expected format
			const inputType = this.getPropertyInputType(key)

			// Convert to appropriate format for the input type
			try {
				const date = new Date(currentValue)
				if (isNaN(date.getTime())) {
					return ''
				}

				switch (inputType) {
				case 'date':
					// Format as YYYY-MM-DD
					return date.toISOString().split('T')[0]
				case 'time':
					// Format as HH:MM
					return date.toTimeString().split(' ')[0].substring(0, 5)
				case 'datetime-local': {
					// Format as YYYY-MM-DDTHH:MM
					const isoString = date.toISOString()
					return isoString.substring(0, 16) // Remove seconds and timezone
				}
				default:
					return currentValue
				}
			} catch (e) {
				return currentValue
			}
		},
		getDateTimePickerValue(key, value) {
			// Get the current value (either from formData or original value)
			const currentValue = this.formData[key] !== undefined ? this.formData[key] : value

			if (!currentValue) {
				return null
			}

			// Get schema information to handle different date formats properly
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			const format = schemaProperty?.format

			// NcDateTimePicker expects a Date object or null
			try {
				let date

				if (format === 'date') {
					// For date-only fields, ensure we create the date correctly
					// to avoid timezone issues
					if (typeof currentValue === 'string' && currentValue.match(/^\d{4}-\d{2}-\d{2}$/)) {
						// Create date at noon to avoid timezone issues
						date = new Date(currentValue + 'T12:00:00')
					} else {
						date = new Date(currentValue)
					}
				} else if (format === 'time') {
					// For time-only fields, create a date with today's date
					if (typeof currentValue === 'string' && currentValue.match(/^\d{2}:\d{2}(:\d{2})?$/)) {
						const today = new Date().toISOString().split('T')[0]
						date = new Date(today + 'T' + currentValue)
					} else {
						date = new Date(currentValue)
					}
				} else {
					// For datetime fields, use as-is
					date = new Date(currentValue)
				}

				if (isNaN(date.getTime())) {
					return null
				}
				return date
			} catch (e) {
				return null
			}
		},
		getDateTimePickerType(key) {
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]

			if (!schemaProperty || !schemaProperty.format) {
				return 'datetime'
			}

			// Map schema formats to NcDateTimePicker types
			switch (schemaProperty.format) {
			case 'date':
				return 'date'
			case 'date-time':
				return 'datetime'
			case 'time':
				return 'time'
			default:
				return 'datetime'
			}
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
				if (!this.currentObject) {
					throw new Error('No object to publish')
				}

				const { registerId, schemaId } = this.getRegisterSchemaIds(this.currentObject)
				const objectId = this.currentObject['@self']?.id || this.currentObject.id

				let endpoint
				let body = {}

				if (this.showPublishModal && this.publishDate) {
					// Publishing with a specific date from the modal
					endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}`
					body = {
						...this.currentObject,
						'@self': {
							...this.currentObject['@self'],
							published: this.publishDate instanceof Date ? this.publishDate.toISOString() : this.publishDate,
						},
					}
				} else {
					// Direct publish action (publish now)
					endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/publish`
				}

				const response = await fetch(endpoint, {
					method: this.showPublishModal ? 'PUT' : 'POST',
					headers: this.showPublishModal
						? {
							'Content-Type': 'application/json',
						}
						: undefined,
					body: this.showPublishModal ? JSON.stringify(body) : undefined,
				})

				if (!response.ok) {
					const errorText = await response.text()
					throw new Error(`Failed to publish object: ${response.status} ${response.statusText} - ${errorText}`)
				}

				const result = await response.json()

				// Rebuild the object with schema properties like we do in objectProperties computed
				const updatedObject = this.rebuildObjectWithSchemaProperties(result)

				// Update the current object with the rebuilt data
				objectStore.setActiveObject('publication', updatedObject)

				// Refresh the publications list
				catalogStore.fetchPublications()

				this.closePublishModal()
				this.success = 'Object published successfully'
				setTimeout(() => {
					this.success = null
				}, 3000)
			} catch (error) {
				console.error('Failed to publish object:', error)
				this.error = 'Failed to publish object: ' + error.message
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
				if (!this.currentObject) {
					throw new Error('No object to depublish')
				}

				const { registerId, schemaId } = this.getRegisterSchemaIds(this.currentObject)
				const objectId = this.currentObject['@self']?.id || this.currentObject.id

				let endpoint
				let body = {}

				if (this.showDepublishModal && this.depublishDate) {
					// Depublishing with a specific date from the modal
					endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}`
					body = {
						...this.currentObject,
						'@self': {
							...this.currentObject['@self'],
							depublished: this.depublishDate instanceof Date ? this.depublishDate.toISOString() : this.depublishDate,
						},
					}
				} else {
					// Direct depublish action (depublish now)
					endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/depublish`
				}

				const response = await fetch(endpoint, {
					method: this.showDepublishModal ? 'PUT' : 'POST',
					headers: this.showDepublishModal
						? {
							'Content-Type': 'application/json',
						}
						: undefined,
					body: this.showDepublishModal ? JSON.stringify(body) : undefined,
				})

				if (!response.ok) {
					const errorText = await response.text()
					throw new Error(`Failed to depublish object: ${response.status} ${response.statusText} - ${errorText}`)
				}

				const result = await response.json()

				// Rebuild the object with schema properties like we do in objectProperties computed
				const updatedObject = this.rebuildObjectWithSchemaProperties(result)

				// Update the current object with the rebuilt data
				objectStore.setActiveObject('publication', updatedObject)

				// Refresh the publications list
				catalogStore.fetchPublications()

				this.closeDepublishModal()
				this.success = 'Object depublished successfully'
				setTimeout(() => {
					this.success = null
				}, 3000)
			} catch (error) {
				console.error('Failed to depublish object:', error)
				this.error = 'Failed to depublish object: ' + error.message
				setTimeout(() => {
					this.error = null
				}, 5000)
			} finally {
				this.isDepublishing = false
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
		/**
		 * Truncate file name to prevent dialog alignment issues
		 * @param {string} fileName - The file name to truncate
		 * @return {string} The truncated file name (22 chars + ... if longer than 25)
		 */
		truncateFileName(fileName) {
			if (!fileName) return ''
			if (fileName.length <= 25) return fileName
			return fileName.substring(0, 22) + '...'
		},
		toggleSelectAllFiles(checked) {
			if (checked) {
				// Add all current page files to selection
				this.paginatedFiles.forEach(file => {
					if (!this.selectedAttachments.includes(file.id)) {
						this.selectedAttachments.push(file.id)
					}
				})
			} else {
				// Remove all current page files from selection
				const currentPageIds = this.paginatedFiles.map(file => file.id)
				this.selectedAttachments = this.selectedAttachments.filter(id => !currentPageIds.includes(id))
			}
		},
		toggleFileSelection(fileId, checked) {
			if (checked) {
				if (!this.selectedAttachments.includes(fileId)) {
					this.selectedAttachments.push(fileId)
				}
			} else {
				this.selectedAttachments = this.selectedAttachments.filter(id => id !== fileId)
			}
		},
		onFilesPageChanged(page) {
			this.filesCurrentPage = page
		},
		onFilesPageSizeChanged(pageSize) {
			this.filesPerPage = pageSize
			this.filesCurrentPage = 1
		},
		async publishSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.publishLoading = [...this.selectedAttachments]

				// Get the selected files
				const selectedFiles = this.paginatedFiles.filter(file =>
					this.selectedAttachments.includes(file.id),
				)

				// Publish each file individually
				for (const file of selectedFiles) {
					const publication = this.currentObject
					const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
					const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files/${encodeURIComponent(file.title || file.name || file.path)}/publish`

					const response = await fetch(endpoint, {
						method: 'POST',
					})

					if (!response.ok) {
						throw new Error(`Failed to publish file ${file.title || file.name}: ${response.statusText}`)
					}
				}

				// Refresh files list once after all operations with publication data
				const publication = this.currentObject
				const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
				const publicationData = {
					source: 'openregister',
					schema: schemaId,
					register: registerId,
				}
				await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', {}, publicationData)

				// Clear selection after successful operation
				this.selectedAttachments = []

			} catch (error) {
				console.error('Error publishing files:', error)
			} finally {
				this.publishLoading = []
			}
		},
		async depublishSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.depublishLoading = [...this.selectedAttachments]

				// Get the selected files
				const selectedFiles = this.paginatedFiles.filter(file =>
					this.selectedAttachments.includes(file.id),
				)

				// Depublish each file individually
				for (const file of selectedFiles) {
					const publication = this.currentObject
					const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
					const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files/${encodeURIComponent(file.title || file.name || file.path)}/depublish`

					const response = await fetch(endpoint, {
						method: 'POST',
					})

					if (!response.ok) {
						throw new Error(`Failed to depublish file ${file.title || file.name}: ${response.statusText}`)
					}
				}

				// Refresh files list once after all operations with publication data
				const publication = this.currentObject
				const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
				const publicationData = {
					source: 'openregister',
					schema: schemaId,
					register: registerId,
				}
				await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', {}, publicationData)

				// Clear selection after successful operation
				this.selectedAttachments = []

			} catch (error) {
				console.error('Error depublishing files:', error)
			} finally {
				this.depublishLoading = []
			}
		},
		async deleteSelectedFiles() {
			if (this.selectedAttachments.length === 0) return

			try {
				this.fileIdsLoading = [...this.selectedAttachments]

				// Get the selected files
				const selectedFiles = this.paginatedFiles.filter(item =>
					this.selectedAttachments.includes(item.id),
				)

				// Delete each selected file
				for (const file of selectedFiles) {
					const publication = this.currentObject
					const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
					const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files/${encodeURIComponent(file.title || file.name || file.path)}`

					const response = await fetch(endpoint, {
						method: 'DELETE',
					})

					if (!response.ok) {
						throw new Error(`Failed to delete file ${file.title || file.name}: ${response.statusText}`)
					}
				}

				// Refresh files list once after all operations with publication data
				const publication = this.currentObject
				const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
				const publicationData = {
					source: 'openregister',
					schema: schemaId,
					register: registerId,
				}
				await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', {}, publicationData)

				// Clear selection - files list is automatically refreshed by the store methods
				this.selectedAttachments = []
			} catch (error) {
				console.error('Failed to delete selected files:', error)
			} finally {
				this.fileIdsLoading = []
			}
		},
		async publishFile(file) {
			try {
				this.publishLoading.push(file.id)

				const publication = this.currentObject
				const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files/${encodeURIComponent(file.title || file.name || file.path)}/publish`

				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`Failed to publish file: ${response.statusText}`)
				}

				// Refresh files list with publication data
				const publicationData = {
					source: 'openregister',
					schema: schemaId,
					register: registerId,
				}
				await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', {}, publicationData)
			} catch (error) {
				console.error('Failed to publish file:', error)
			} finally {
				this.publishLoading = this.publishLoading.filter(id => id !== file.id)
			}
		},
		async depublishFile(file) {
			try {
				this.depublishLoading.push(file.id)

				const publication = this.currentObject
				const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files/${encodeURIComponent(file.title || file.name || file.path)}/depublish`

				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(`Failed to depublish file: ${response.statusText}`)
				}

				// Refresh files list with publication data
				const publicationData = {
					source: 'openregister',
					schema: schemaId,
					register: registerId,
				}
				await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', {}, publicationData)
			} catch (error) {
				console.error('Failed to depublish file:', error)
			} finally {
				this.depublishLoading = this.depublishLoading.filter(id => id !== file.id)
			}
		},
		async deleteFile(file) {
			try {
				this.fileIdsLoading.push(file.id)

				const publication = this.currentObject
				const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files/${encodeURIComponent(file.title || file.name || file.path)}`

				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(`Failed to delete file: ${response.statusText}`)
				}

				// Refresh files list with publication data
				const publicationData = {
					source: 'openregister',
					schema: schemaId,
					register: registerId,
				}
				await objectStore.fetchRelatedData('publication', this.currentObject.id, 'files', {}, publicationData)
			} catch (error) {
				console.error('Failed to delete file:', error)
			} finally {
				this.fileIdsLoading = this.fileIdsLoading.filter(id => id !== file.id)
			}
		},
		editFileLabels(file) {
			// You'll need to implement the labels editing functionality
			// This could open a modal or inline editor for file labels
			// console.log('Editing labels for file:', file.name)
			// Placeholder for labels editing implementation
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
			// Close the ViewObject modal first
			this.closeModal()
			// Open the upload files modal (same as in PublicationDetail.vue)
			navigationStore.setModal('uploadFiles')
		},
		shouldShowPublishAction(object) {
			if (!object || !object['@self']) return false
			return object['@self'].published === null || object['@self'].published === undefined
		},
		shouldShowDepublishAction(object) {
			if (!object || !object['@self']) return false
			return object['@self'].published !== null && object['@self'].published !== undefined
		},
		singlePublishObject() {
			if (!this.currentObject) return

			// Set the single publication as selected object (as full object, not just ID)
			const publicationObject = {
				...this.currentObject,
				id: this.currentObject['@self']?.id || this.currentObject.id,
			}
			objectStore.setSelectedObjects([publicationObject])

			// Open the mass publish dialog
			navigationStore.setDialog('massPublishObjects')
		},
		singleDepublishObject() {
			if (!this.currentObject) return

			// Set the single publication as selected object (as full object, not just ID)
			const publicationObject = {
				...this.currentObject,
				id: this.currentObject['@self']?.id || this.currentObject.id,
			}
			objectStore.setSelectedObjects([publicationObject])

			// Open the mass depublish dialog
			navigationStore.setDialog('massDepublishObjects')
		},
		singleDeleteObject() {
			if (!this.currentObject) return

			// Set the single publication as selected object (as full object, not just ID)
			const publicationObject = {
				...this.currentObject,
				id: this.currentObject['@self']?.id || this.currentObject.id,
			}
			objectStore.setSelectedObjects([publicationObject])

			// Open the mass delete dialog
			navigationStore.setDialog('massDeleteObject')
		},
		// Schema handling methods
		getSchemaProperties() {
			// For new objects, use the selected schema
			if (this.isNewObject && this.selectedSchema) {
				const fullSchema = objectStore.availableSchemas.find(schema => schema.id === this.selectedSchema.id)
				return fullSchema?.properties || {}
			}

			// For existing objects, try to get schema from the object's schema reference
			if (this.currentObject && this.currentObject['@self']?.schema) {
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
						return fullSchema.properties
					}
				}
			}

			// Try to get schema properties from the catalogStore
			if (this.currentSchema?.properties) {
				return this.currentSchema.properties
			}

			// Fallback: return empty object
			return {}
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
						// Use edited value from formData
						objectData[propertyKey] = cleanedFormData[propertyKey]
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

			// Don't show drop button for const properties
			const schemaProperties = this.getSchemaProperties()
			const schemaProperty = schemaProperties[key]
			if (schemaProperty?.const !== undefined) {
				return false
			}

			// Show drop button if:
			// 1. Property has a value (either in formData or original object)
			// 2. Property exists in current object or has been edited
			const hasFormValue = this.formData[key] !== undefined
			const hasOriginalValue = this.currentObject && Object.prototype.hasOwnProperty.call(this.currentObject, key)

			return hasFormValue || hasOriginalValue
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

		// Enhanced property validation and editing methods (from openregister version)
		isValidPropertyValue(key, value, schemaProperty) {
			// Handle null/undefined values
			if (value === null || value === undefined || value === '') {
				// Check if property is required
				const isRequired = schemaProperty.required
				return !isRequired // Valid if not required, invalid if required
			}

			// Validate based on schema type
			switch (schemaProperty.type) {
			case 'string':
				if (typeof value !== 'string') return false
				// Check format constraints
				if (schemaProperty.format === 'date-time') {
					return this.isValidDate(value)
				}
				// Check const constraint
				if (schemaProperty.const && value !== schemaProperty.const) {
					return false
				}
				return true

			case 'number':
				return typeof value === 'number' && !isNaN(value)

			case 'boolean':
				return typeof value === 'boolean'

			case 'array':
				return Array.isArray(value)

			case 'object':
				return typeof value === 'object' && value !== null && !Array.isArray(value)

			default:
				return true // Unknown type, assume valid
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
	opacity: 1;
	transition: opacity 0.2s ease;
	margin-left: auto;
	flex-shrink: 0;
}

.drop-property-btn:hover {
	opacity: 1 !important;
	background-color: var(--color-error-hover) !important;
	color: var(--color-error) !important;
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
	vertical-align: top;
}

.tableColumnExpanded {
	text-align: left;
	vertical-align: top;
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

</style>
