<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
/** * @file SelectAttachmentsList.vue * @module Components * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2 * @version 1.0.0 */

<script setup>
import { objectStore } from '../store/store.js'
</script>

<template>
	<SelectionItemsList
		:items="selectedAttachments"
		:title="title"
		:emptyTitle="emptyTitle"
		:emptyDescription="emptyDescription"
		:showRemove="showRemove"
		:itemName="getObjectName"
		:itemSubtitle="getAttachmentSize"
		:itemError="getObjectError"
		@remove="(attachment) => removeObject(attachment.id)" />
</template>

<script>
import SelectionItemsList from './SelectionItemsList.vue'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'SelectAttachmentsList',
	components: {
		SelectionItemsList,
	},

	props: {
		/**
		 * Title for the selected objects section
		 */
		title: {
			type: String,
			default: 'Selected Attachments',
		},

		/**
		 * Title to show when no objects are selected
		 */
		emptyTitle: {
			type: String,
			default: 'No attachments selected',
		},

		/**
		 * Description to show when no objects are selected
		 */
		emptyDescription: {
			type: String,
			default: 'No attachments are currently selected.',
		},

		/**
		 * Array of attachment IDs to display (optional, if not provided uses selected attachments from store)
		 */
		attachments: {
			type: Array,
			default: null,
		},

		/**
		 * Whether to show remove buttons
		 */
		showRemove: {
			type: Boolean,
			default: true,
		},
	},

	computed: {
		/**
		 * Get selected attachment IDs (either from props or from store)
		 *
		 * @return {Array<string|number>} Array of attachment IDs
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7 */
		selectedAttachmentIds() {
			return (
				this.attachments
				|| (Array.isArray(objectStore.selectedAttachments)
					? objectStore.selectedAttachments
					: [])
			)
		},

		/**
		 * Map selected IDs to detailed attachment objects from the active publication files
		 *
		 * @return {Array<object>} attachments with id, name/title, size
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7 */
		selectedAttachments() {
			const currentPublication = objectStore.getActiveObject('publication')
			if (!currentPublication) return []
			const filesData = objectStore.getRelatedData('publication', 'files')
			const files = filesData?.results || []
			return this.selectedAttachmentIds.map((id) => {
				const file = files.find((f) => f.id === id)
				return file || { id, name: `#${id}`, size: null }
			})
		},
	},

	methods: {
		/**
		 * Remove attachment ID from selected attachments in the store
		 *
		 * @param {string|number} attachmentId - The attachment ID to remove
		 */
		/**
		 * @param attachmentId
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		removeObject(attachmentId) {
			const currentSelected = Array.isArray(objectStore.selectedAttachments)
				? [...objectStore.selectedAttachments]
				: []
			const index = currentSelected.findIndex((id) => id === attachmentId)
			if (index > -1) {
				currentSelected.splice(index, 1)
				objectStore.setSelectedAttachments(currentSelected)
			}
		},

		/**
		 * Get display name for an attachment
		 *
		 * @param {object} attachment - The attachment object
		 * @return {string}
		 */
		getObjectName(attachment) {
			return attachment?.name || attachment?.title || `#${attachment?.id}`
		},

		/**
		 * Get formatted size for an attachment
		 *
		 * @param {object} attachment - The attachment object
		 * @return {string}
		 */
		/**
		 * @param attachment
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		getAttachmentSize(attachment) {
			if (!attachment || typeof attachment.size !== 'number') return ''
			return this.formatFileSize(attachment.size)
		},

		/**
		 * Get error message for an item (kept for compatibility; returns null for attachments by default)
		 *
		 * @param {object} obj - The object to get error for
		 * @return {string|null}
		 */
		/**
		 * @param obj
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		getObjectError(obj) {
			const objectId = obj?.id || obj?.['@self']?.id
			return objectStore.getObjectError
				? objectStore.getObjectError(objectId)
				: null
		},

		/**
		 * Format file size (bytes to human-readable)
		 *
		 * @param {number} bytes - File size in bytes to format
		 * @return {string}
		 */
		/**
		 * @param bytes
		 * @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-7
		 */
		formatFileSize(bytes) {
			const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
			if (!bytes || bytes <= 0) return 'n/a'
			const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)))
			if (i === 0 && sizes[i] === 'Bytes') return '< 1 KB'
			if (i === 0) return bytes + ' ' + sizes[i]
			return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
		},
	},
}
</script>
