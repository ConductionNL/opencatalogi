<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="dialogTitle"
		:canClose="true"
		size="normal"
		class="mass-action-dialog"
		@update:open="handleDialogClose">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="publish-step">
			<NcNoteCard type="info">
				<template v-if="operation === 'publish'">
					{{
						t(
							'opencatalogi',
							'Attachments will be published with the current date and time. If any attachments have a depublication date set, it will be removed to make them fully published.',
						)
					}}
				</template>
				<template v-else>
					{{
						t(
							'opencatalogi',
							'Selected attachments will be depublished immediately and will no longer be publicly accessible.',
						)
					}}
				</template>
			</NcNoteCard>

			<SelectAttachmentsList
				:title="t('opencatalogi', 'Selected Attachments')"
				:emptyTitle="t('opencatalogi', 'No attachments selected')"
				:emptyDescription="
					t('opencatalogi', 'No attachments are currently selected.')
				"
				:attachments="filteredAttachmentIds"
				:showRemove="true" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					operation === 'publish'
						? t('opencatalogi', 'Attachment successfully published')
						: t('opencatalogi', 'Attachment successfully depublished')
				}}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null
						? t('opencatalogi', 'Cancel')
						: t('opencatalogi', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading || (attachments?.length || 0) === 0"
				variant="primary"
				@click="process()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Publish v-if="!loading && operation === 'publish'" :size="20" />
					<LockOutline
						v-if="!loading && operation === 'depublish'"
						:size="20" />
				</template>
				{{
					operation === 'publish'
						? t('opencatalogi', 'Publish')
						: t('opencatalogi', 'Depublish')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import SelectAttachmentsList from '../../components/SelectAttachmentsList.vue'

/**
 * MassAttachmentModal — bulk attachment operations built on useFileSelection.
 *
 * @spec openspec/specs/file-management/spec.md
 */
/**
 * Whether a file is shared: it has an access or a download URL.
 *
 * @param {object} file The file.
 * @return {boolean} Whether it is shared.
 */
function isShared(file) {
	return Boolean(file.accessUrl || file.downloadUrl)
}

export default {
	name: 'MassAttachmentModal',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SelectAttachmentsList,
		// Icons
		Publish,
		LockOutline,
		Cancel,
	},

	props: {
		// No props needed - always uses selected objects from store
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			result: null,
			closeModalTimeout: null,
			originalSelectedCount: 0,
			operation: 'publish',
			attachments: [],
		}
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to publish
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6 */
		objectsToPublish() {
			return objectStore.selectedAttachments || []
		},

		/**
		 * IDs filtered by operation and current file states
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6 */
		filteredAttachmentIds() {
			const ids = Array.isArray(this.attachments) ? this.attachments : []
			const filesData = objectStore.getRelatedData('publication', 'files')
			const files = filesData?.results || []
			// Publish acts on the files not shared yet, depublish on the shared ones.
			const wantShared = this.operation !== 'publish'
			return ids.filter((id) => {
				const f = files.find((x) => x.id === id)
				return f && isShared(f) === wantShared
			})
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6 */
		filteredCount() {
			return this.filteredAttachmentIds.length
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6 */
		dialogTitle() {
			const count = this.filteredCount
			if (count === 1) {
				return this.operation === 'publish'
					? 'Publish attachment'
					: 'Depublish attachment'
			}
			return `${this.operation === 'publish' ? 'Publish' : 'Depublish'} ${count} ${count !== 1 ? 'attachments' : 'attachment'}`
		},
	},

	mounted() {
		this.initializeSelection()
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6 */
		initializeSelection() {
			// Pick data from navigationStore dialog properties
			const props = navigationStore.dialogProperties || {}
			this.operation = props.operation || 'publish'
			const ids = Array.isArray(props.attachments)
				? props.attachments
				: objectStore.selectedAttachments || []
			this.attachments = ids
			this.originalSelectedCount = this.filteredAttachmentIds.length
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6 */
		closeDialog() {
			// Clear any pending timeout that might reopen the dialog
			if (this.closeModalTimeout) {
				clearTimeout(this.closeModalTimeout)
				this.closeModalTimeout = null
			}
			navigationStore.setDialog(false)
		},

		/**
		 * @param isOpen
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeDialog()
			}
		},

		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-6 */
		async process() {
			this.loading = true

			try {
				const { successful, failed } = await this.runOperation([
					...(this.filteredAttachmentIds || []),
				])

				if (successful.length > 0) {
					// Only auto-close if there are no failures
					this.markSucceeded(failed.length === 0)
				}

				if (failed.length > 0) {
					this.error = this.failureMessage(failed.length)
				}
			} catch (error) {
				this.success = false
				this.error = this.errorMessage(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Publish or depublish the attachments through the store.
		 *
		 * @param {Array<string>} ids The attachment ids.
		 * @return {Promise<object>} The successful and failed ids.
		 */
		runOperation(ids) {
			return this.operation === 'publish'
				? objectStore.massPublishAttachments(ids)
				: objectStore.massDepublishAttachments(ids)
		},

		/**
		 * Show the success, refresh the publications, and close in two seconds when asked.
		 *
		 * @param {boolean} closeAfter Whether to close the dialog after the feedback.
		 * @return {void}
		 */
		markSucceeded(closeAfter) {
			this.success = true
			// Refresh publications using catalogStore
			catalogStore.fetchPublications()

			if (closeAfter) {
				this.closeModalTimeout = setTimeout(() => {
					this.closeDialog()
				}, 2000)
			}
		},

		/**
		 * What the store threw, or a message naming the operation when it said nothing.
		 *
		 * @param {Error} error The error.
		 * @return {string} The message.
		 */
		errorMessage(error) {
			return (
				error.message
				|| `An error occurred while ${this.operation === 'publish' ? 'publishing' : 'depublishing'} attachments`
			)
		},

		/**
		 * The message for attachments that failed.
		 *
		 * @param {number} count How many failed.
		 * @return {string} The message.
		 */
		failureMessage(count) {
			return `${this.operation === 'publish' ? 'Failed to publish' : 'Failed to depublish'} ${count} attachment${count > 1 ? 's' : ''}`
		},
	},
}
</script>

<style scoped>
.publish-step {
	padding: 0;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}
</style>
