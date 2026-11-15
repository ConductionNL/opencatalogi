<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { getRequestToken } from '@nextcloud/auth'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcModal
		v-if="navigationStore.dialog === 'uploadFiles'"
		ref="modalRef"
		labelId="AddAttachmentModal"
		@close="closeDialog()">
		<div class="modal__content TestMappingMainModal">
			<h2>{{ t('opencatalogi', 'Add attachment') }}</h2>

			<div class="labelAndShareContainer">
				<NcSelect
					:modelValue="labelOptions.value"
					:options="labelOptions.options"
					:inputLabel="t('opencatalogi', 'Labels')"
					:disabled="loading || retryLoading || tagsLoading"
					:loading="tagsLoading"
					:taggable="true"
					:multiple="true"
					@update:modelValue="onLabelSelectionChange" />
				<NcCheckboxRadioSwitch
					v-model="share"
					:disabled="loading || retryLoading"
					:label="t('opencatalogi', 'Automatically publish')"
					type="switch">
					{{ t('opencatalogi', 'Automatically publish') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="container">
				<div
					v-if="!labelOptions.value?.length || loading || retryLoading"
					class="filesListDragDropNotice tabPanelFileUpload">
					<div v-if="!labelOptions.value?.length">
						<NcNoteCard type="info">
							<p>
								{{
									t(
										'opencatalogi',
										'Select or create labels or select "No label" to add files',
									)
								}}
							</p>
						</NcNoteCard>
					</div>
					<UploadDropNotice
						:success="success"
						:error="error"
						:duplicateWarning="duplicateWarning"
						:labelCount="labelOptions.value?.length"
						:loading="loading"
						:retryLoading="retryLoading"
						@openFileUpload="openFileUpload()" />
				</div>
				<div
					v-if="labelOptions.value?.length && !loading && !retryLoading"
					ref="dropZoneRef"
					class="filesListDragDropNotice tabPanelFileUpload">
					<div v-if="!labelOptions.value?.length">
						<NcNoteCard type="info">
							<p>
								{{
									t(
										'opencatalogi',
										'Select or create labels or select "No label" to add files',
									)
								}}
							</p>
						</NcNoteCard>
					</div>
					<div v-if="checkForTooBigFiles(files)">
						<NcNoteCard type="warning">
							<p class="folderLink">
								{{
									t(
										'opencatalogi',
										'If you want to add files larger than or equal to 512MB, go to the',
									)
								}}
								<NcButton
									variant="secondary"
									class="folderLinkButton"
									:aria-label="t('opencatalogi', 'Open folder')"
									@click="
										openFolder(
											publicationStore.publicationItem?.[
												'@self'
											]?.folder,
										)
									">
									<template #icon>
										<FolderOutline :size="20" />
									</template>
									{{ t('opencatalogi', 'folder') }}
								</NcButton>
								{{ t('opencatalogi', 'and add the files there.') }}
							</p>
						</NcNoteCard>
					</div>
					<UploadDropNotice
						:success="success"
						:error="error"
						:duplicateWarning="duplicateWarning"
						:labelCount="labelOptions.value?.length"
						:loading="loading"
						:retryLoading="retryLoading"
						@openFileUpload="openFileUpload()" />
				</div>
				<table v-if="files" class="files-table">
					<thead>
						<tr class="files-table-tr">
							<th scope="col" class="files-table-td-status">
								<span class="hidden-visually">{{
									t('opencatalogi', 'Status')
								}}</span>
							</th>
							<th scope="col">
								{{ t('opencatalogi', 'File name') }}
							</th>
							<th scope="col">
								{{ t('opencatalogi', 'Size') }}
							</th>
							<th scope="col">
								{{ t('opencatalogi', 'Labels') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="file of files"
							:key="file.name"
							class="files-table-tr">
							<td>
								<CheckCircle
									v-if="file.status === 'uploaded'"
									class="success"
									:size="20" />
								<NcLoadingIcon
									v-if="file.status === 'uploading'"
									:size="20" />
								<AlphaXCircle
									v-if="file.status === 'failed'"
									class="failed"
									:size="20" />
								<Exclamation
									v-if="file.status === 'too_large'"
									class="failed"
									:size="20" />
							</td>
							<td
								class="files-table-td-name"
								:class="{
									'files-table-name-wrong': getTooBigFiles(
										file.size,
									),
								}">
								<span class="files-table-name">{{
									getFileNameAndExtension(file.name).name
								}}</span>
								<span class="files-table-extension"
									>.{{
										getFileNameAndExtension(file.name).extension
									}}</span
								>
							</td>
							<td>
								{{ bytesToSize(file.size) }}
							</td>
							<td class="files-table-td-labels">
								<span
									v-if="editingTags !== file.name"
									class="files-list__row-action--inline files-list__row-action-system-tags">
									<ul
										v-if="file.tags && file.tags.length > 0"
										class="files-list__system-tags"
										:aria-label="
											t(
												'opencatalogi',
												'Assigned collaborative tags',
											)
										">
										<li
											v-for="label of file.tags"
											:key="label"
											class="files-list__system-tag"
											:title="label">
											{{ label }}
										</li>
									</ul>
									<span
										v-if="!file.tags || file.tags.length === 0">
										{{ t('opencatalogi', 'No labels') }}
									</span>
								</span>
								<NcSelect
									v-if="editingTags === file.name"
									v-model="editedTags"
									:disabled="
										loading || retryLoading || tagsLoading
									"
									:loading="tagsLoading"
									:taggable="true"
									:multiple="true"
									:aria-label-combobox="
										labelOptionsEdit.inputLabel
									"
									:options="labelOptionsEdit.options" />

								<span class="buttonContainer">
									<!-- Tags Buttons -->
									<NcButton
										v-if="editingTags !== file.name"
										:title="t('opencatalogi', 'Edit labels')"
										:disabled="
											(editingTags
												&& editingTags !== file.name)
											|| loading
											|| retryLoading
											|| file.status === 'too_large'
											|| tagsLoading
										"
										:aria-label="
											t(
												'opencatalogi',
												'Edit tags for {name}',
												{ name: file.name },
											)
										"
										variant="secondary"
										class="editTagsButton"
										@click="
											((editingTags = file.name),
											(editedTags = file.tags))
										">
										<template #icon>
											<TagEdit :size="20" />
										</template>
									</NcButton>
									<NcButton
										v-if="editingTags === file.name"
										:title="t('opencatalogi', 'Save labels')"
										variant="primary"
										:aria-label="
											t(
												'opencatalogi',
												'Save tags for {name}',
												{ name: file.name },
											)
										"
										class="editTagsButton"
										@click="saveTags(file, editedTags)">
										<template #icon>
											<ContentSaveOutline :size="20" />
										</template>
									</NcButton>
									<NcButton
										v-if="editingTags === file.name"
										:aria-label="t('opencatalogi', 'Cancel')"
										:title="t('opencatalogi', 'Cancel')"
										variant="secondary"
										@click="cancelFileLabelEditing">
										<template #icon>
											<Cancel :size="20" />
										</template>
									</NcButton>

									<!-- File Actions -->
									<NcButton
										v-if="file.status === 'failed'"
										:aria-label="
											t('opencatalogi', 'Retry upload')
										"
										:title="t('opencatalogi', 'Retry upload')"
										variant="primary"
										@click="addAttachments(file)">
										<template #icon>
											<Refresh :size="20" />
										</template>
									</NcButton>
									<NcButton
										v-if="file.status === 'too_large'"
										:aria-label="
											t('opencatalogi', 'Remove from list')
										"
										:title="
											t('opencatalogi', 'Remove from list')
										"
										variant="primary"
										@click="removeFile(file.name)">
										<template #icon>
											<Minus :size="20" />
										</template>
									</NcButton>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="modalFooter">
				<div class="modalFooterStatus">
					<span v-if="!files">{{
						t('opencatalogi', 'No files selected')
					}}</span>
					<span v-if="files" class="uploadSummary">{{
						t('opencatalogi', '{uploaded} / {total} files uploaded', {
							uploaded: uploadedCount,
							total: files.length,
						})
					}}</span>
				</div>
				<div class="buttonContainer">
					<NcButton
						v-if="files && failedCount > 0"
						variant="secondary"
						:disabled="loading || retryLoading"
						@click="retryAllFailed">
						<template #icon>
							<Refresh
								:size="20"
								:class="{ loadingIcon: retryLoading }" />
						</template>
						{{
							retryLoading
								? t('opencatalogi', 'In progress...')
								: t('opencatalogi', 'Retry all ({count})', {
										count: failedCount,
									})
						}}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="loading || retryLoading"
						@click="closeDialog()">
						{{ t('opencatalogi', 'Done') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { isRef, ref } from 'vue'
import AlphaXCircle from 'vue-material-design-icons/AlphaXCircle.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Exclamation from 'vue-material-design-icons/Exclamation.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Minus from 'vue-material-design-icons/Minus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import TagEdit from 'vue-material-design-icons/TagEdit.vue'
import UploadDropNotice from './UploadDropNotice.vue'
import { Attachment } from '../../entities/index.js'
import { EventBus } from '../../eventBus.js'
import { fileSizeLabel } from '../../services/fileSizeLabel.js'
import { registerSchemaIdsOf } from '../../services/registerSchemaIds.js'
import {
	closingTags,
	knownTags,
	labelOptionLists,
} from '../../services/uploadTags.js'
import { useFileSelection } from './../../composables/UseFileSelection.js'
import {
	countSettled,
	embeddedSchemaConfiguration,
	fetchSchemaConfiguration,
	notifyPublicationChanged,
	publicationFilesUrl,
	recordCreatedId,
} from './uploadSteps.js'

const dropZoneRef = ref()

const { openFileUpload, files, reset, setTags, rejectedDuplicates } =
	useFileSelection({
		allowMultiple: true,
		dropzone: dropZoneRef,
	})

/**
 * The labels-select option that uploads files without tags. Options are plain
 * strings, so this translated text is also the option's identity.
 *
 * @return {string}
 */
const noLabelOption = () => t('opencatalogi', 'No label')

/**
 * Keep a publication's attachments in the object store.
 *
 * @param {object|null} attachments - The files answer
 * @return {void}
 */
function storeAttachments(attachments) {
	objectStore.setCollection(
		'publicationAttachments',
		attachments?.results || [],
	)
}

/**
 * Set a file's status from its upload answer, noting the created id when
 * an id map is given.
 *
 * @param {object} file - The file
 * @param {{response: object, idMap: object|null}} upload - The answer and the id map
 * @return {void}
 */
function markUploadAnswer(file, { response, idMap }) {
	if (response.status !== 200) {
		file.status = 'failed'
		return
	}
	file.status = 'uploaded'
	if (idMap) {
		recordCreatedId(file, { response, idMap })
	}
}

/**
 * PUT the labels of an uploaded file.
 *
 * @param {string} endpoint - The file's URL
 * @param {Array<string>} editedTags - The labels
 * @return {Promise<void>}
 * @throws {Error} When the labels are refused
 */
async function putFileTags(endpoint, editedTags) {
	const response = await fetch(endpoint, {
		method: 'PUT',
		headers: { requesttoken: getRequestToken(), 'Content-Type': 'application/json' },
		body: JSON.stringify({ tags: editedTags }),
	})

	if (!response.ok) {
		throw new Error(`Failed to update file tags: ${response.statusText}`)
	}
}

/**
 * UploadFiles — upload files to a publication's OpenRegister files endpoint.
 *
 * @spec openspec/specs/file-management/spec.md
 */
export default {
	name: 'UploadFiles',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Cancel,
		UploadDropNotice,
	},

	props: {
		dropFiles: {
			type: Array,
			required: false,
			default: null,
		},
	},

	data() {
		return {
			loading: false,
			retryLoading: false,
			success: null,
			error: false,
			duplicateWarning: null,
			share: false,
			editingTags: null,
			editedTags: [],
			labelOptions: {
				options: [noLabelOption()],
				value: [],
			},

			labelOptionsEdit: {
				inputLabel: 'Labels',
				multiple: true,
			},

			tagsLoading: false,
			uploadedCount: 0,
			failedCount: 0,
			initialTags: [],
			latestTags: [],
			newTags: [],
		}
	},

	computed: {
		// only used for watching
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		files() {
			return files
		},

		// only used for watching
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		rejectedDuplicatesList() {
			return rejectedDuplicates
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		inputValidation() {
			const catalogiItem = new Attachment({
				...objectStore.getActiveObject('attachment'),
			})

			const result = catalogiItem.validate()

			return {
				success: result.success,
				errorMessages:
					result?.error?.issues.map(
						(issue) => `${issue.path.join('.')}: ${issue.message}`,
					) || [],

				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},

	watch: {
		files: {
			/**
			 * @param newFiles
			 * @param oldFiles
			 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
			 */
			handler(newFiles, oldFiles) {
				if (newFiles.value?.length) {
					this.addAttachments()
				}
			},

			deep: true,
		},

		labelOptions: {
			/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
			handler() {
				setTags(this.getLabels())
			},

			deep: true,
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		success() {
			this.updateUploadCounts()
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		error() {
			this.updateUploadCounts()
		},

		rejectedDuplicatesList: {
			/**
			 * @param newRef
			 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
			 */
			handler(newRef) {
				const newRejected = newRef?.value?.names
				if (!Array.isArray(newRejected) || newRejected.length === 0) return
				// Clear any prior success/error so this warning isn't masked by
				// `v-if="error && !success"` and so the auto-clear timeout from
				// a previous warning doesn't truncate this one.
				this.success = null
				this.duplicateWarning = t(
					'opencatalogi',
					'These files were skipped because a file with the same name already exists: {names}',
					{ names: newRejected.join(', ') },
				)
				if (this._duplicateWarningTimer)
					clearTimeout(this._duplicateWarningTimer)
				this._duplicateWarningTimer = setTimeout(() => {
					this.duplicateWarning = null
				}, 5000)
			},

			deep: true,
		},
	},

	/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
	mounted() {
		objectStore.setActiveObject('attachment', [])
		this.getAllTags()
		this.updateUploadCounts()
		// watch for dialog open/close toggles to fire every time
		if (this._uploadFilesDialogUnwatch) this._uploadFilesDialogUnwatch()
		this._uploadFilesDialogUnwatch = this.$watch(
			() => navigationStore.dialog,
			(newVal, oldVal) => {
				if (newVal === 'uploadFiles' && oldVal !== 'uploadFiles') {
					this.onOpenModal()
				}
				if (oldVal === 'uploadFiles' && newVal !== 'uploadFiles') {
					this.onExternalClose()
				}
			},
			{ immediate: true },
		)
	},

	/**
	 * Tear down the dialog watcher and the pending duplicate-file warning timer.
	 *
	 * @return {void}
	 * @spec openspec/specs/retrofit-2026-05-26-object-modals/spec.md#requirement-object-file-attachment-management-req-objm-004
	 */
	unmounted() {
		if (this._uploadFilesDialogUnwatch)
			try {
				this._uploadFilesDialogUnwatch()
			} catch (e) {}
		// The duplicate-warning timeout writes `this.duplicateWarning` 5s later;
		// without this it fires against an unmounted instance.
		if (this._duplicateWarningTimer) {
			clearTimeout(this._duplicateWarningTimer)
			this._duplicateWarningTimer = null
		}
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		closeDialog() {
			// mark internal close to avoid duplicate external watcher emission
			this.__uploadFilesClosingInternally = true
			const tagsToEmit = closingTags(this.latestTags, objectStore)
			// ensure latest tags are saved globally
			try {
				objectStore.setCollection('tags', tagsToEmit)
			} catch (e) {}
			// emit closed and tags-updated once
			this.emitClosingTags(tagsToEmit, this.newTags)
			navigationStore.setDialog(null)
			objectStore.setActiveObject(
				'publication',
				objectStore.getActiveObject('publication'),
			)
			catalogStore.fetchPublications()
			this.resetDialogState()
			setTimeout(() => {
				this.__uploadFilesClosingInternally = false
			}, 0)
		},

		/**
		 * @param bytes
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		bytesToSize(bytes) {
			return fileSizeLabel(bytes)
		},

		/**
		 * @param fullname
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		getFileNameAndExtension(fullname) {
			const lastDot = fullname.lastIndexOf('.')
			const name = fullname.slice(0, lastDot)
			const extension = fullname.slice(lastDot + 1)
			return { name, extension }
		},

		/**
		 * @param files
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		checkForTooBigFiles(files) {
			if (!files) return false
			const wrongFiles = files.filter((file) => {
				return this.getTooBigFiles(file.size)
			})

			wrongFiles.forEach((file) => {
				file.status = 'too_large'
			})

			return wrongFiles.length > 0
		},

		getTooBigFiles(size) {
			return size > 536870480 // 512MB
		},

		/**
		 * Apply a labels-select change. "No label" and real labels exclude each
		 * other: picking "No label" clears the labels, and picking a label while
		 * "No label" is selected replaces it.
		 *
		 * @param {Array<string>|null} selected The select's new value.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		onLabelSelectionChange(selected) {
			const noLabel = noLabelOption()
			const list = Array.isArray(selected) ? selected : []
			if (list.includes(noLabel) && list.length > 1) {
				this.labelOptions.value = this.labelOptions.value?.includes(noLabel)
					? list.filter((label) => label !== noLabel)
					: [noLabel]
				return
			}
			this.labelOptions.value = list
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		getLabels() {
			if (this.labelOptions.value?.includes(noLabelOption())) {
				return null
			} else {
				return this.labelOptions.value
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		async getAllTags() {
			this.tagsLoading = true
			try {
				const response = await fetch(
					'/index.php/apps/openregister/api/tags',
					{ method: 'get' },
				)
				const data = await response.json().catch(() => [])

				this.applyTagLists(
					labelOptionLists(knownTags(data, objectStore), noLabelOption()),
				)
				this.shareLatestTags()
			} catch (e) {
				console.error('Failed to fetch tags', e)
			} finally {
				this.tagsLoading = false
			}
		},

		/**
		 * Take fetched label lists as the options, and track the latest tags
		 * and the ones new since the dialog opened.
		 *
		 * @param {{options: Array<string>, edit: Array<string>}} lists - The label option lists
		 * @return {void}
		 */
		applyTagLists(lists) {
			this.labelOptions.options = lists.options
			this.labelOptionsEdit.options = lists.edit

			if (this.initialTags.length === 0) {
				this.initialTags = [...lists.edit]
			}
			this.latestTags = [...lists.edit]
			this.newTags = this.latestTags.filter(
				(t) => !this.initialTags.includes(t),
			)
		},

		/**
		 * Share the latest tags: in the store for other views, and as an
		 * event for listeners.
		 *
		 * @return {void}
		 */
		shareLatestTags() {
			// save globally so other views may pull
			try {
				objectStore.setCollection('tags', this.latestTags)
			} catch (e) {}
			// notify listeners about fresh tags
			EventBus.$emit('upload-files:tags-fetched', {
				tags: this.latestTags,
				newTags: this.newTags,
			})
		},

		/**
		 * Announce the dialog closing with its tags, and the updated tags
		 * when there are any.
		 *
		 * @param {Array<string>} tagsToEmit - The tags
		 * @param {Array<string>|undefined} newTags - The tags new since opening
		 * @return {void}
		 */
		emitClosingTags(tagsToEmit, newTags) {
			EventBus.$emit('upload-files:closed', {
				tags: tagsToEmit,
				newTags,
			})
			if (tagsToEmit.length > 0) {
				EventBus.$emit('upload-files:tags-updated', {
					tags: tagsToEmit,
					newTags,
				})
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		onOpenModal() {
			this.initialTags = []
			this.latestTags = []
			this.newTags = []
			this.labelOptions.value = []
			EventBus.$emit('upload-files:opened')
			this.getAllTags()
			this.applySchemaDefaults()
		},

		/**
		 * Seed the `share` toggle from the active publication's schema
		 * (`configuration.defaultAutoShare`). Falls back to `false`
		 * when the schema, the key or the lookup itself is missing —
		 * existing behaviour for schemas that don't opt in.
		 *
		 * @spec openspec/changes/feature-2026-05-26-default-auto-share/tasks.md#task-1
		 * @return {Promise<void>}
		 */
		async applySchemaDefaults() {
			this.share = false
			try {
				const publication = objectStore.getActiveObject('publication')
				const schemaRef = publication?.['@self']?.schema
				if (!schemaRef) return

				const configuration =
					embeddedSchemaConfiguration(schemaRef)
					|| (await fetchSchemaConfiguration(schemaRef))

				if (configuration?.defaultAutoShare === true) {
					this.share = true
				}
			} catch (e) {
				// Non-fatal — keep the safe default (off) so a network
				// hiccup never silently flips publications to "share on upload".
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		onExternalClose() {
			if (this.__uploadFilesClosingInternally) {
				this.__uploadFilesClosingInternally = false
				return
			}
			const tagsToEmit = closingTags(this.latestTags, objectStore)
			// emit the same close events as closeDialog
			this.emitClosingTags(tagsToEmit, this.newTags || [])
			this.resetDialogState()
		},

		/**
		 * Clear the file selection, messages, label selection, per-file label editing and tag bookkeeping.
		 * The fetched label options are kept.
		 *
		 * @return {void}
		 * @spec openspec/specs/file-management/spec.md
		 */
		resetDialogState() {
			this.success = null
			this.error = null
			this.duplicateWarning = null
			if (this._duplicateWarningTimer) {
				clearTimeout(this._duplicateWarningTimer)
				this._duplicateWarningTimer = null
			}
			reset()
			this.labelOptions.value = []
			this.editingTags = null
			this.editedTags = []
			this.initialTags = []
			this.latestTags = []
			this.newTags = []
		},

		/**
		 * Opens the folder URL in a new tab after parsing the encoded URL and converting to Nextcloud format
		 *
		 * @param {string} url - The encoded folder URL to open (e.g. "Open Registers\/Publicatie Register\/Publicatie\/123")
		 */
		/**
		 * @param url
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		openFolder(url) {
			// Parse the encoded URL by replacing escaped characters
			const decodedUrl = url.replace(/\\\//g, '/')

			// Ensure URL starts with forward slash
			const normalizedUrl = decodedUrl.startsWith('/')
				? decodedUrl
				: '/' + decodedUrl

			// Construct the proper Nextcloud Files app URL with the normalized path
			// Use window.location.origin to get the current domain instead of hardcoding
			const nextcloudUrl = `${window.location.origin}/index.php/apps/files/files?dir=${encodeURIComponent(normalizedUrl)}`

			// Open URL in new tab
			window.open(nextcloudUrl, '_blank')
		},

		/**
		 * @param file
		 * @param editedTags
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		async saveTags(file, editedTags) {
			try {
				if (file && file.id) {
					await this.saveFileTags(file, editedTags)
				} else {
					await this.uploadWithTags(file, editedTags)
				}
			} catch (error) {
				console.error('Error saving tags:', error)
				this.error =
					'Failed to save file labels: ' + (error?.message || error)
				setTimeout(() => {
					this.error = null
				}, 5000)
			} finally {
				this.editingTags = null
				this.editedTags = []
			}
		},

		/**
		 * Store the labels of an uploaded file, then refresh the attachments
		 * and the publications list.
		 *
		 * @param {object} file - The uploaded file
		 * @param {Array<string>} editedTags - The labels
		 * @return {Promise<void>}
		 * @throws {Error} When the labels are refused
		 */
		async saveFileTags(file, editedTags) {
			const publication = objectStore.getActiveObject('publication')
			const { registerId, schemaId } = this.getRegisterSchemaIds(publication)
			const filesUrl = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/files`

			await putFileTags(`${filesUrl}/${file.id}`, editedTags)

			try {
				file.tags = Array.isArray(editedTags) ? [...editedTags] : []
			} catch (_) {}

			const getAttachments = await fetch(filesUrl)
			const attachments = await getAttachments.json().catch(() => null)
			try {
				storeAttachments(attachments)
			} catch (_) {}
			catalogStore.fetchPublications()

			this.success = 'File labels updated successfully'
			setTimeout(() => {
				this.success = null
			}, 3000)
		},

		/**
		 * Upload a pending file with its labels and take over the id it gets.
		 *
		 * @param {object} file - The pending file
		 * @param {Array<string>} editedTags - The labels
		 * @return {Promise<void>}
		 */
		async uploadWithTags(file, editedTags) {
			file.tags = editedTags
			file.status = 'pending'
			const idMap = await this.addAttachments(file)
			const newId = idMap && (idMap[file.name] || idMap[file.title] || null)
			try {
				if (newId) file.id = newId
			} catch (_) {}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		cancelFileLabelEditing() {
			this.editingTags = null
			this.editedTags = []
		},

		/**
		 * @param fileName
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		removeFile(fileName) {
			reset(fileName)
			if (this.editingTags === fileName) {
				this.editingTags = null
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		checkIfDisabled() {
			if (
				objectStore.getActiveObject('attachment').downloadUrl
				|| objectStore.getActiveObject('attachment').title
			)
				return true
			return false
		},

		/**
		 * @param specificFile
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		async addAttachments(specificFile = null) {
			this.loading = true
			this.error = null

			try {
				const idMap = {}
				const filesToUpload = this.filesToUpload(specificFile)

				if (filesToUpload.length === 0) {
					this.loading = false
					return
				}

				const results = await this.uploadAll(filesToUpload, idMap)
				await this.afterUpload(results)

				this.updateUploadCounts()
				if (specificFile) return idMap
			} catch (err) {
				this.error = err.response?.data?.error ?? err
			} finally {
				this.loading = false
				this.tagsLoading = false
			}
		},

		/**
		 * The files an upload sends: the one given, else the pending files
		 * that are not too big.
		 *
		 * @param {object|null} specificFile - One file to upload
		 * @return {Array<object>} The files
		 */
		filesToUpload(specificFile) {
			if (specificFile) {
				return [specificFile]
			}

			const pending = this.files.value.filter(
				(file) =>
					file.status !== 'uploaded'
					&& file.status !== 'uploading'
					&& file.status !== 'failed',
			)
			return pending.filter((file) => !this.getTooBigFiles(file.size))
		},

		/**
		 * Mark every file as uploading, then upload them all at once.
		 *
		 * @param {Array<object>} filesToUpload - The files
		 * @param {object} idMap - Collects the created ids by file name
		 * @return {Promise<Array>} The settled uploads
		 */
		uploadAll(filesToUpload, idMap) {
			filesToUpload.forEach((file) => {
				file.status = 'uploading'
			})

			return Promise.allSettled(
				filesToUpload.map((file) => this.uploadFile(file, idMap)),
			)
		},

		/**
		 * Upload one file and set its status from the answer, updating the
		 * counts either way.
		 *
		 * @param {object} file - The file
		 * @param {object|null} idMap - Collects the created id, when given
		 * @return {Promise<object>} The answer
		 */
		uploadFile(file, idMap) {
			return this.createPublicationAttachment([file], reset, this.share)
				.then((response) => {
					markUploadAnswer(file, { response, idMap })
					this.updateUploadCounts()
					return response
				})
				.catch((error) => {
					file.status = 'failed'
					this.updateUploadCounts()
					throw error
				})
		},

		/**
		 * After an upload: report the change, refresh the tags, attachments
		 * and publications list, and show the outcome.
		 *
		 * @param {Array} results - The settled uploads
		 * @return {Promise<void>}
		 */
		async afterUpload(results) {
			const publication = objectStore.getActiveObject('publication')
			const ids = this.getRegisterSchemaIds(publication)
			// Refresh the list before the follow-up fetches below can fail.
			if (results.some((r) => r.status === 'fulfilled')) {
				notifyPublicationChanged(ids, publication)
			}

			await this.getAllTags()

			const getAttachments = await fetch(publicationFilesUrl(ids, publication))
			storeAttachments(await getAttachments.json())

			catalogStore.fetchPublications()

			this.reportUploadResults(results, publication)
		},

		/**
		 * Show the first rejected upload's error, or success, and announce
		 * the counts.
		 *
		 * @param {Array} results - The settled uploads
		 * @param {object} publication - The publication
		 * @return {void}
		 */
		reportUploadResults(results, publication) {
			const rejected = results.filter((r) => r.status === 'rejected')
			if (rejected.length > 0) {
				const firstError = rejected[0].reason
				this.error = firstError?.response?.data?.error ?? String(firstError)
			} else {
				this.success = true
			}

			EventBus.$emit('upload-files:uploaded', {
				publicationId: publication.id,
				uploadedCount: countSettled(results, 'fulfilled'),
				failedCount: rejected.length,
			})
		},

		// Utility method to get register and schema IDs from publication object
		/**
		 * @param publication
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		getRegisterSchemaIds(publication) {
			return registerSchemaIdsOf(publication)
		},

		/**
		 * @param files
		 * @param reset
		 * @param share
		 * @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4
		 */
		async createPublicationAttachment(files, reset, share = false) {
			if (!files) {
				throw Error('No files to import')
			}
			if (!reset) {
				throw Error('No reset function to call')
			}

			// Flatten and format the files and tags
			if (isRef(files)) files = files.value

			// At this point, files should be an array.
			if (!Array.isArray(files)) {
				throw new Error('Files is not an array')
			}

			return await this.postAttachments(this.attachmentFormData(files, share))
		},

		/**
		 * The multipart form of an upload: every file with its labels, and
		 * the share flag.
		 *
		 * @param {Array<File>} files - The files
		 * @param {boolean} share - Whether to publish them right away
		 * @return {FormData} The form
		 */
		attachmentFormData(files, share) {
			const formData = new FormData()
			files.forEach((file) => {
				formData.append('files[]', file)
				if (file.tags?.length > 0) {
					formData.append('tags[]', file.tags.join(','))
				}
			})
			formData.append('share', share.toString())
			return formData
		},

		/**
		 * POST a multipart attachment form to the active publication.
		 *
		 * @param {FormData} formData - The form
		 * @return {Promise<object>} The response
		 */
		postAttachments(formData) {
			const publication = objectStore.getActiveObject('publication')
			const { registerId, schemaId } = this.getRegisterSchemaIds(publication)

			return axios
				.post(
					`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${publication.id}/filesMultipart`,
					formData,
					{
						headers: {
							'Content-Type': 'multipart/form-data',
						},
					},
				)
				.then((response) => {
					console.info('Importing files:', response.data)
					return response
				})
				.catch((err) => {
					console.error('Error importing files:', err)
					throw err
				})
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		async retryAllFailed() {
			this.retryLoading = true
			const uploadPromises = this.files.value
				.filter((file) => file.status === 'failed')
				.map((file) => {
					file.status = 'uploading'
					return this.uploadFile(file, null)
				})

			const results = await Promise.allSettled(uploadPromises)

			const publication = objectStore.getActiveObject('publication')
			if (publication?.id) {
				await this.afterRetry(results, publication)
			}

			this.updateUploadCounts()
			this.retryLoading = false
		},

		/**
		 * After a retry: refresh when an upload succeeded, then announce the
		 * counts.
		 *
		 * @param {Array} results - The settled uploads
		 * @param {object} publication - The publication
		 * @return {Promise<void>}
		 */
		async afterRetry(results, publication) {
			const fulfilledCount = countSettled(results, 'fulfilled')
			const rejectedCount = countSettled(results, 'rejected')
			if (fulfilledCount > 0) {
				await this.refreshAfterRetry(publication)
			}
			EventBus.$emit('upload-files:uploaded', {
				publicationId: publication.id,
				uploadedCount: fulfilledCount,
				failedCount: rejectedCount,
			})
		},

		/**
		 * Report the change and refresh the attachments after a retry; a
		 * failure here is ignored.
		 *
		 * @param {object} publication - The publication
		 * @return {Promise<void>}
		 */
		async refreshAfterRetry(publication) {
			try {
				const ids = this.getRegisterSchemaIds(publication)
				notifyPublicationChanged(ids, publication)
				const getAttachments = await fetch(
					publicationFilesUrl(ids, publication),
				)
				const attachments = await getAttachments.json().catch(() => null)
				storeAttachments(attachments)
			} catch (_) {
				/* ignore */
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-object-modals/tasks.md#task-4 */
		updateUploadCounts() {
			if (!this.files || !this.files.value) {
				this.uploadedCount = 0
				this.failedCount = 0
				return
			}
			const filesArr = this.files.value
			this.uploadedCount = filesArr.filter(
				(f) => f.status === 'uploaded',
			).length
			this.failedCount = filesArr.filter((f) => f.status === 'failed').length
		},
	},
}
</script>

<style>
div[class='modal-container']:has(.TestMappingMainModal) {
	width: clamp(1000px, 100%, 1200px) !important;
	z-index: 13000 !important;
}

div[class='modal-container']:has(.TestMappingMainModal) .modal-mask {
	z-index: 12999 !important;
}

div[class='modal-container']:has(.TestMappingMainModal) .modal,
div[class='modal-container']:has(.TestMappingMainModal) .modal__content {
	z-index: 13000 !important;
}
</style>

<style scoped>
.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}

.success {
	color: var(--color-element-success);
}

.folderLink {
	display: flex;
	align-items: center;
}

.folderLinkButton {
	margin-inline-start: 1ch;
	margin-inline-end: 1ch;
}

.importButtonContainer {
	display: flex;
	justify-content: flex-end;
}

.container {
	padding-inline: 25px;
}

.modalFooter {
	position: relative;
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 10px;
	padding: 0 25px 15px 25px;
}

.modalFooterStatus {
	position: absolute;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}

.files-table-name-wrong > span {
	color: var(--color-error) !important;
}

.files-table {
	width: 100%;
	border-collapse: collapse;
}

.files-table-td-name {
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	max-width: 75ch;
}

.files-table-td-name span {
	float: inline-start;
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
	max-width: calc(100% - 15%);
}

.files-table-td-status {
	width: 40px;
}

.files-table-name {
	color: var(--color-main-text);
}

.files-table-extension {
	color: var(--color-text-maxcontrast);
}

.files-table-tr {
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
}

.files-table-tr:hover {
	background-color: var(--color-background-hover);
	--color-text-maxcontrast: var(--color-main-text);
	--color-border: var(--color-border-dark);
}

.files-table-tr > td {
	height: 55px;
}

.files-table-remove-button {
	text-align: -webkit-right;
}

.files-list__row-icon {
	position: relative;
	display: flex;
	overflow: visible;
	align-items: center;
	flex: 0 0 32px;
	justify-content: center;
	width: 32px;
	height: 100%;
	margin-inline-end: var(--checkbox-padding);
	color: var(--color-primary-element);
}

.files-list__row-action-system-tags {
	margin-inline-end: 7px;
	display: flex;
}

.files-list__system-tags {
	--min-size: 32px;
	display: flex;
	justify-content: center;
	align-items: center;
	min-width: calc(var(--min-size) * 2);
	max-width: 300px;
}

.files-list__system-tag {
	padding: 5px 10px;
	border: 1px solid;
	border-radius: var(--border-radius-pill);
	border-color: var(--color-border);
	color: var(--color-text-maxcontrast);
	height: var(--min-size);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	line-height: 22px;
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

.files-table-td-labels {
	display: flex;
	justify-content: space-between;
	text-align: unset;
	align-items: center;
	align-items: end;
	box-sizing: border-box;
}

.labelAndShareContainer {
	display: flex;
	justify-content: center;
	align-items: end;
	margin-block-end: 15px;
	gap: 10px;
}

.failed {
	color: var(--color-element-error);
}

.buttonContainer {
	display: flex;
	gap: 10px;
}

.uploadSummaryContainer {
	margin-block-end: 20px;
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.uploadSummary {
	font-weight: bold;
}

.loadingIcon {
	animation: spin 1s linear infinite;
}

/* WCAG 2.3.3. Slowed rather than stopped: this spinner is the only signal that
   an upload is still in flight, so removing its motion would remove the
   information. */
@media (prefers-reduced-motion: reduce) {
	.loadingIcon {
		animation-duration: 6s;
	}
}

@keyframes spin {
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}
</style>
