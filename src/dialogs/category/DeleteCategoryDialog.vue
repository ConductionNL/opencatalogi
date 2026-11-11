<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import Delete from 'vue-material-design-icons/Delete.vue'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<ConfirmObjectActionDialog
		dialog="deleteCategory"
		objectType="category"
		:canClose="false"
		:title="t('opencatalogi', 'Delete Category')"
		:actionLabel="t('opencatalogi', 'Delete')"
		:actionIcon="Delete"
		actionIconName="Delete"
		actionVariant="error"
		@confirm="deleteCategory()">
		<template #success>
			{{ t('opencatalogi', 'Category successfully deleted') }}
		</template>
		<template #failure>
			{{
				t(
					'opencatalogi',
					'Something went wrong while deleting the category',
				)
			}}
		</template>
		<template #loading>
			{{ t('opencatalogi', 'Category is being deleted...') }}
		</template>
		<template #question>
			{{
				t(
					'opencatalogi',
					'Do you want to delete {name}? This action cannot be undone.',
					{ name: objectStore.getActiveObject('category')?.name },
				)
			}}
		</template>
	</ConfirmObjectActionDialog>
</template>

<script>
import ConfirmObjectActionDialog from '../generic/ConfirmObjectActionDialog.vue'

/**
 * Delete Category Dialog Component
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
	name: 'DeleteCategoryDialog',
	components: {
		ConfirmObjectActionDialog,
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

			objectStore.deleteObject('category', activeCategory.id).then(() => {
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
