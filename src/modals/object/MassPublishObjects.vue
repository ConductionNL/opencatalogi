/**
 * @file MassPublishObjects.vue
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
	<NcDialog :name="`Publish ${selectedObjects.length} publication${selectedObjects.length !== 1 ? 's' : ''}`"
		:can-close="false"
		size="normal">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="publish-step">
			<h3 class="step-title">
				Confirm Object Publication
			</h3>

			<NcNoteCard type="info">
				Review the selected objects below. You can remove any objects you don't want to publish by clicking the remove button.<br><br>
				Objects will be published with the current date and time. If any objects have a depublication date set, it will be removed to make them fully published.
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>Selected Objects ({{ selectedObjects.length }})</h4>

				<div v-if="selectedObjects.length" class="selected-objects-list">
					<div v-for="obj in selectedObjects"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{ obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || 'Unnamed Object' }}</strong>
							<p class="object-id">
								ID: {{ obj.id || obj['@self']?.id }}
							</p>
						</div>
						<NcButton type="tertiary"
							:aria-label="`Remove ${obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || obj.id}`"
							@click="removeObject(obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-else name="No objects selected">
					<template #description>
						No objects are currently selected for publication.
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Object{{ selectedObjects.length > 1 ? 's' : '' }} successfully published</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? 'Cancel' : 'Close' }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="loading || selectedObjects.length === 0"
				type="primary"
				@click="publishObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Publish v-if="!loading" :size="20" />
				</template>
				Publish
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'MassPublishObjects',
	components: {
		NcDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		Publish,
		Cancel,
		Close,
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			result: null,
			closeModalTimeout: null,
			selectedObjects: [],
		}
	},
	mounted() {
		this.initializeSelection()
	},
	methods: {
		initializeSelection() {
			// Get selected objects from the store or navigation context
			this.selectedObjects = objectStore.selectedObjects || []
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(obj => obj.id !== objectId)
			// Update the store as well
			objectStore.selectedObjects = this.selectedObjects
			if (this.selectedObjects.length === 0) {
				this.closeDialog()
			}
		},
		closeDialog() {
			clearTimeout(this.closeModalTimeout)
			this.startClosing = true
			navigationStore.setDialog(false)
		},
		async publishObjects() {
			this.loading = true

			try {
				const publishedDate = new Date().toISOString()

				// Publish each object individually using OpenRegister API
				const results = await Promise.allSettled(
					this.selectedObjects.map(async (obj) => {
						try {
							// Extract register and schema IDs (handle objects)
							const registerId = typeof obj['@self']?.register === 'object'
								? obj['@self'].register?.id || obj['@self'].register?.uuid
								: obj['@self']?.register
							const schemaId = typeof obj['@self']?.schema === 'object'
								? obj['@self'].schema?.id || obj['@self'].schema?.uuid
								: obj['@self']?.schema

							const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${obj.id}/publish`

							const response = await fetch(endpoint, {
								method: 'POST',
							})

							if (!response.ok) {
								throw new Error(`Failed to publish object: ${response.statusText}`)
							}

							return { success: true, id: obj.id }
						} catch (error) {
							console.error(`Failed to publish object ${obj.id}:`, error)
							return { success: false, id: obj.id, error: error.message }
						}
					}),
				)

				// Count successful and failed operations
				const successful = results.filter(r => r.status === 'fulfilled' && r.value.success)
				const failed = results.filter(r => r.status === 'rejected' || (r.status === 'fulfilled' && !r.value.success))

				if (successful.length > 0) {
					this.success = true
					// Clear selected objects and refresh the publication list
					objectStore.selectedObjects = []
					// Refresh publications using catalogStore
					catalogStore.fetchPublications()

					this.closeModalTimeout = setTimeout(() => {
						this.closeDialog()
					}, 2000)
				}

				if (failed.length > 0) {
					this.error = `Failed to publish ${failed.length} object${failed.length > 1 ? 's' : ''}`
				}

			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while publishing objects'
			} finally {
				this.loading = false
			}
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
}

.selected-object-item:last-child {
	border-bottom: none;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
