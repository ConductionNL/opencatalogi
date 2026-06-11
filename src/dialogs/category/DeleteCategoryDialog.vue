<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'deleteCategory'"
		:name="t('opencatalogi', 'Delete Category')"
		:can-close="false">
		<div v-if="objectStore.getState('category').success !== null || objectStore.getState('category').error">
			<NcNoteCard v-if="objectStore.getState('category').success" type="success">
				<p>{{ t('opencatalogi', 'Category successfully deleted') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!objectStore.getState('category').success" type="error">
				<p>{{ t('opencatalogi', 'Something went wrong while deleting the category') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="objectStore.getState('category').error" type="error">
				<p>{{ objectStore.getState('category').error }}</p>
			</NcNoteCard>
		</div>
		<div v-if="objectStore.isLoading('category')" class="loading-status">
			<NcLoadingIcon :size="20" />
			<span>{{ t('opencatalogi', 'Category is being deleted...') }}</span>
		</div>
		<p v-if="objectStore.getState('category').success === null && !objectStore.isLoading('category')">
			{{ t('opencatalogi', 'Do you want to delete {name}? This action cannot be undone.', { name: objectStore.getActiveObject('category')?.name }) }}
		</p>
		<template v-if="objectStore.getState('category').success === null && !objectStore.isLoading('category')" #actions>
			<NcButton
				:disabled="objectStore.isLoading('category')"
				icon=""
				@click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="objectStore.isLoading('category')"
				icon="Delete"
				type="error"
				@click="deleteCategory()">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete') }}
			</NcButton>
		</template>
		<template v-else #actions>
			<NcButton
				icon=""
				@click="navigationStore.setDialog(false)">
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
 * Delete Category Dialog Component
 * @module Dialogs
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 * @spec openspec/changes/retrofit-2026-05-25-generic-object-modals/tasks.md#task-4
 */
export default {
	name: 'DeleteCategoryDialog',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		// Icons
		Cancel,
		Delete,
	},
	methods: {
		/**
		 * Delete the active category
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-2 */
		deleteCategory() {
			const activeCategory = objectStore.getActiveObject('category')
			if (!activeCategory?.id) return

			objectStore.deleteObject('category', activeCategory.id)
				.then(() => {
					// Wait for the user to read the feedback then close the dialog
					setTimeout(() => {
						objectStore.setState('category', { success: null, error: null })
						navigationStore.setDialog(false)
					}, 2000)
				})
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
