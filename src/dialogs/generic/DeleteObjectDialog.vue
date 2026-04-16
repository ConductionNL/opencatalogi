<script setup>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { navigationStore, objectStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="shouldShowDialog"
		:name="t('opencatalogi', 'Delete {title}', { title: `${dialogTitle}${isMultiple ? 's' : ''}` })"
		:can-close="false">
		<div v-if="objectStore.getState(objectType).success !== null || objectStore.getState(objectType).error">
			<NcNoteCard v-if="objectStore.getState(objectType).success" type="success">
				<p>{{ t('opencatalogi', '{title} successfully deleted', { title: `${dialogTitle}${isMultiple ? 's' : ''}` }) }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!objectStore.getState(objectType).success && objectStore.getState(objectType).error !== 'Invalid configuration for object type: publication'" type="error">
				<p>{{ t('opencatalogi', 'Something went wrong while deleting {title}', { title: `${dialogTitle.toLowerCase()}${isMultiple ? 's' : ''}` }) }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="objectStore.getState(objectType).error && objectStore.getState(objectType).error !== 'Invalid configuration for object type: publication'" type="error">
				<p>{{ objectStore.getState(objectType).error }}</p>
			</NcNoteCard>
		</div>
		<div v-if="objectStore.isLoading(objectType)" class="loading-status">
			<NcLoadingIcon :size="20" />
			<span>{{ t('opencatalogi', '{title} {count} being deleted...', { title: `${dialogTitle}${isMultiple ? 's' : ''}`, count: isMultiple ? t('opencatalogi', 'are') : t('opencatalogi', 'is') }) }}</span>
		</div>
		<p v-if="objectStore.getState(objectType).success === null && !objectStore.isLoading(objectType)">
			<template v-if="isMultiple">
				{{ t('opencatalogi', 'Do you want to delete the selected {title}s? This action cannot be undone.', { title: dialogTitle.toLowerCase() }) }}
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
		dialogProperties() {
			return navigationStore.dialogProperties
		},
		dialogTitle() {
			return this.dialogProperties?.dialogTitle
		},
		isMultiple() {
			return this.dialogProperties?.isMultiple ?? false
		},
		shouldShowDialog() {
			return navigationStore.dialog === 'deleteObject'
		},
	},
	watch: {
		dialogProperties: {
			immediate: true,
			handler(newProps) {
				this.objectType = newProps?.objectType
			},
		},
	},
	methods: {
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
		refreshObjectList(objectType) {
			switch (objectType) {
			case 'publication':
				catalogStore.fetchPublications()
				break
			default:
				objectStore.fetchCollection(objectType)
			}
		},
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
