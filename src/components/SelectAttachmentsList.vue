/**
 * @file SelectAttachmentsList.vue
 * @module Components
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore } from '../store/store.js'
</script>

<template>
	<div class="selected-objects-container">
		<h4>{{ title }} ({{ selectedAttachments.length }})</h4>

		<div v-if="selectedAttachments.length" class="selected-objects-list">
			<TransitionGroup name="list" tag="div">
				<div v-for="attachment in selectedAttachments"
					:key="attachment.id"
					class="selected-object-item"
					:class="{ 'has-error': getObjectError(attachment) }">
					<div class="object-info">
						<strong>{{ getObjectName(attachment) }}</strong>
						<p class="object-schema">
							{{ getAttachmentSize(attachment) }}
						</p>
						<p v-if="getObjectError(attachment)" class="object-error">
							<AlertCircle :size="16" />
							{{ getObjectError(attachment) }}
						</p>
					</div>
					<NcButton v-if="showRemove"
						type="tertiary"
						:aria-label="`Remove ${getObjectName(attachment)}`"
						@click="removeObject(attachment.id)">
						<template #icon>
							<Close :size="20" />
						</template>
					</NcButton>
				</div>
			</TransitionGroup>
		</div>

		<NcEmptyContent v-else :name="emptyTitle">
			<template #description>
				{{ emptyDescription }}
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import {
	NcButton,
	NcEmptyContent,
} from '@nextcloud/vue'

import Close from 'vue-material-design-icons/Close.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'SelectAttachmentsList',
	components: {
		NcButton,
		NcEmptyContent,
		Close,
		AlertCircle,
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
		 * @return {Array<string|number>} Array of attachment IDs
		 */
		selectedAttachmentIds() {
			return this.attachments || (Array.isArray(objectStore.selectedAttachments) ? objectStore.selectedAttachments : [])
		},
		/**
		 * Map selected IDs to detailed attachment objects from the active publication files
		 * @return {Array<object>} attachments with id, name/title, size
		 */
		selectedAttachments() {
			const currentPublication = objectStore.getActiveObject('publication')
			if (!currentPublication) return []
			const filesData = objectStore.getRelatedData('publication', 'files')
			const files = filesData?.results || []
			return this.selectedAttachmentIds.map(id => {
				const file = files.find(f => f.id === id)
				return file || { id, name: `#${id}`, size: null }
			})
		},
	},
	methods: {
		/**
		 * Remove attachment ID from selected attachments in the store
		 * @param {string|number} attachmentId - The attachment ID to remove
		 */
		removeObject(attachmentId) {
			const currentSelected = Array.isArray(objectStore.selectedAttachments) ? [...objectStore.selectedAttachments] : []
			const index = currentSelected.findIndex(id => id === attachmentId)
			if (index > -1) {
				currentSelected.splice(index, 1)
				objectStore.setSelectedAttachments(currentSelected)
			}
		},

		/**
		 * Get display name for an attachment
		 * @param {object} attachment - The attachment object
		 * @return {string}
		 */
		getObjectName(attachment) {
			return attachment?.name || attachment?.title || `#${attachment?.id}`
		},

		/**
		 * Get formatted size for an attachment
		 * @param {object} attachment - The attachment object
		 * @return {string}
		 */
		getAttachmentSize(attachment) {
			if (!attachment || typeof attachment.size !== 'number') return ''
			return this.formatFileSize(attachment.size)
		},

		/**
		 * Get error message for an item (kept for compatibility; returns null for attachments by default)
		 * @param {object} obj - The object to get error for
		 * @return {string|null}
		 */
		getObjectError(obj) {
			const objectId = obj?.id || obj?.['@self']?.id
			return objectStore.getObjectError ? objectStore.getObjectError(objectId) : null
		},

		/**
		 * Format file size (bytes to human-readable)
		 * @param {number} bytes
		 * @return {string}
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

<style scoped>
.selected-objects-container {
	margin: 20px 0;
}

.selected-objects-list {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	transition: all 0.3s ease;
}

.selected-object-item:last-child {
	border-bottom: none;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-schema {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.object-error {
	color: var(--color-error);
	font-size: 0.85em;
	margin: 4px 0 0 0;
	display: flex;
	align-items: center;
	gap: 4px;
}

.selected-object-item.has-error {
	border-left: 3px solid var(--color-error);
	background-color: var(--color-background-dark);
}

/* Transition animations for list items */
.list-move,
.list-enter-active,
.list-leave-active {
	transition: all 0.3s ease;
}

.list-enter-from,
.list-leave-to {
	opacity: 0;
	transform: translateX(30px);
}

.list-leave-active {
	position: absolute;
	right: 0;
	left: 0;
}
</style>
