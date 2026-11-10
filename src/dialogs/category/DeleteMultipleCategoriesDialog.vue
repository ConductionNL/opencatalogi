<script setup>
import Delete from 'vue-material-design-icons/Delete.vue'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<ConfirmObjectActionDialog
		dialog="deleteMultipleCategories"
		objectType="category"
		:canClose="false"
		:title="t('opencatalogi', 'Delete Categories')"
		:actionLabel="t('opencatalogi', 'Delete')"
		:actionIcon="Delete"
		actionIconName="Delete"
		actionVariant="error"
		@confirm="deleteCategories()">
		<template #success>
			{{ t('opencatalogi', 'Categories successfully deleted') }}
		</template>
		<template #failure>
			{{
				t(
					'opencatalogi',
					'Something went wrong while deleting the categories',
				)
			}}
		</template>
		<template #loading>
			{{ t('opencatalogi', 'Categories are being deleted...') }}
		</template>
		<template #question>
			{{
				t(
					'opencatalogi',
					'Do you want to delete the selected categories? This action cannot be undone.',
				)
			}}
		</template>
	</ConfirmObjectActionDialog>
</template>

<script>
import ConfirmObjectActionDialog from '../generic/ConfirmObjectActionDialog.vue'

/**
 * Delete Multiple Categories Dialog Component
 *
 * @module Dialogs
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'DeleteMultipleCategoriesDialog',
	components: {
		ConfirmObjectActionDialog,
	},

	methods: {
		/**
		 * Delete the selected categories
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-2 */
		deleteCategories() {
			const selectedCategories = objectStore.getSelectedObjects('category')
			if (!selectedCategories?.length) return

			Promise.all(
				selectedCategories.map((category) =>
					objectStore.deleteObject('category', category.id),
				),
			).then(() => {
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
