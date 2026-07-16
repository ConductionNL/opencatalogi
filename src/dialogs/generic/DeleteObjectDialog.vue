<script setup>
import { navigationStore, objectStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="shouldShowDialog"
		:name="isMultiple ? t('opencatalogi', 'Delete {type}s', { type: dialogTitle }) : t('opencatalogi', 'Delete {type}', { type: dialogTitle })"
		:can-close="false">
		<div v-if="objectStore.getState(objectType).success !== null || objectStore.getState(objectType).error">
			<NcNoteCard v-if="objectStore.getState(objectType).success" type="success">
				<p>{{ isMultiple ? t('opencatalogi', '{type}s successfully deleted', { type: dialogTitle }) : t('opencatalogi', '{type} successfully deleted', { type: dialogTitle }) }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!objectStore.getState(objectType).success && objectStore.getState(objectType).error !== 'Invalid configuration for object type: publication'" type="error">
				<p>{{ isMultiple ? t('opencatalogi', 'Something went wrong while deleting {type}s', { type: dialogTitle.toLowerCase() }) : t('opencatalogi', 'Something went wrong while deleting {type}', { type: dialogTitle.toLowerCase() }) }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="objectStore.getState(objectType).error && objectStore.getState(objectType).error !== 'Invalid configuration for object type: publication'" type="error">
				<p>{{ objectStore.getState(objectType).error }}</p>
			</NcNoteCard>
		</div>
		<div v-if="objectStore.isLoading(objectType)" class="loading-status">
			<NcLoadingIcon :size="20" />
			<span>{{ isMultiple ? t('opencatalogi', '{type}s are being deleted...', { type: dialogTitle }) : t('opencatalogi', '{type} is being deleted...', { type: dialogTitle }) }}</span>
		</div>
		<p v-if="objectStore.getState(objectType).success === null && !objectStore.isLoading(objectType)">
			<template v-if="isMultiple">
				{{ t('opencatalogi', 'Do you want to delete the selected {type}s? This action cannot be undone.', { type: dialogTitle.toLowerCase() }) }}
			</template>
			<template v-else>
				{{ t('opencatalogi', 'Do you want to delete {name}? This action cannot be undone.', { name: objectStore.getActiveObject(objectType)?.name || objectStore.getActiveObject(objectType)?.title }) }}
			</template>
		</p>
		<template v-if="objectStore.getState(objectType).success === null && !objectStore.isLoading(objectType)" #actions>
			<NcButton
				:disabled="objectStore.isLoading(objectType)"
				icon=""
				@click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="loading"
				icon="Delete"
				type="error"
				@click="deleteObject">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />

					<Delete v-if="!loading" :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete') }}
			</NcButton>
		</template>
		<template v-else #actions>
			<NcButton
				icon=""
				@click="closeDialog()">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('opencatalogi', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'DeleteObjectDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		// Icons
		Cancel,
		Delete,
	},
	data() {
		return {
			closeTimeout: null,
			objectType: null,
			loading: false,
		}
	},
	computed: {
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		dialogProperties() {
			return navigationStore.dialogProperties
		},
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		dialogTitle() {
			return this.dialogProperties?.dialogTitle
		},
		isMultiple() {
			return this.dialogProperties?.isMultiple ?? false
		},
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		shouldShowDialog() {
			return navigationStore.dialog === 'deleteObject'
		},
	},
	watch: {
		dialogProperties: {
			immediate: true,
			/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
			handler(newProps) {
				this.objectType = newProps?.objectType
			},
		},
	},
	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		deleteObject() {
			this.loading = true
			if (this.isMultiple) {
				const selectedObjects = objectStore.getSelectedObjects(this.objectType)
				if (!selectedObjects?.length) return

				const deletePromises = selectedObjects.map(obj =>
					objectStore.deleteObject(this.objectType, obj.id)
						.catch(err => {
							if (
								this.objectType === 'publication'
              && typeof err.message === 'string'
              && err.message.includes('Invalid configuration for object type: publication')
							) {
								objectStore.setState(this.objectType, { success: true, error: null })
								return {}
							}
							return Promise.reject(err)
						}),
				)

				Promise.all(deletePromises)
					.then(responses => {
						this.closeTimeout = setTimeout(() => {
							this.closeDialog()
						}, 2000)
						this.loading = false
					})
					.catch(err => {
						console.error('Error deleting multiple objects:', err)
						this.loading = false
					})
					.finally(() => {
						this.refreshObjectList(this.objectType)
						this.closeDialog()
						this.loading = false
					})

			} else {
				const activeObject = objectStore.getActiveObject(this.objectType)
				if (!activeObject?.id) return

				const publicationData = {
					schema: activeObject['@self']?.schema,
					register: activeObject['@self']?.register,
				}

				objectStore.deleteObject({
					type: this.dialogProperties.objectType,
					id: activeObject.id,
					...publicationData,
				})
					.catch(err => {
						if (
							this.objectType === 'publication'
            && typeof err.message === 'string'
            && err.message.includes('Invalid configuration for object type: publication')
						) {
							objectStore.setState(this.objectType, { success: true, error: null })
							return {}
						}
						return Promise.reject(err)
					})
					.then(response => {
						this.closeTimeout = setTimeout(() => {
							this.closeDialog()
						}, 2000)
						this.loading = false
					})
					.catch(err => {
						console.error('Error deleting one object:', err)
						this.loading = false
					})
					.finally(() => {
						this.refreshObjectList(this.objectType)
						this.closeDialog()

					})
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		refreshObjectList(objectType) {
			switch (objectType) {
			case 'publication':
				catalogStore.fetchPublications()
				break
			default:
				objectStore.fetchCollection(objectType)
			}
		},
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-1 */
		closeDialog() {
			if (this.closeTimeout) {
				clearTimeout(this.closeTimeout)
				this.closeTimeout = null
			}

			navigationStore.setDialog(false)
			objectStore.setState(this.objectType, { success: null, error: null })
		},

	},
}
</script>

<style>
.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}

.success {
	color: green;
}

.loading-status {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	margin: 1rem 0;
	color: var(--color-text-lighter);
}
</style>
