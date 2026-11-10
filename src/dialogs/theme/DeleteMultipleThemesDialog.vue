<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<DeleteConfirmationDialog
		dialog="deleteMultipleThemes"
		objectType="theme"
		:canClose="false"
		:title="t('opencatalogi', 'Delete Themes')"
		:successText="t('opencatalogi', 'Themes successfully deleted')"
		:failureText="
			t(
				'opencatalogi',
				'Something went wrong while deleting the themes',
			)
		"
		:loadingText="t('opencatalogi', 'Themes are being deleted...')"
		:question="
			t(
				'opencatalogi',
				'Do you want to delete the selected themes? This action cannot be undone.',
			)
		"
		@delete="deleteThemes()" />
</template>

<script>
import DeleteConfirmationDialog from '../generic/DeleteConfirmationDialog.vue'

/**
 * Delete Multiple Themes Dialog Component
 *
 * @module Dialogs
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/opencatalogi}
 */
/**
 * DeleteMultipleThemesDialog — bulk-delete selected themes via deleteObject.
 *
 * @spec openspec/specs/content-management/spec.md
 */
export default {
	name: 'DeleteMultipleThemesDialog',
	components: {
		DeleteConfirmationDialog,
	},

	methods: {
		/**
		 * Delete the selected themes
		 *
		 * @return {void}
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-generic-dialogs/tasks.md#task-2 */
		deleteThemes() {
			const selectedThemes = objectStore.getSelectedObjects('theme')
			if (!selectedThemes?.length) return

			Promise.all(
				selectedThemes.map((theme) =>
					objectStore.deleteObject('theme', theme.id),
				),
			).then(() => {
				// Wait for the user to read the feedback then close the dialog
				setTimeout(() => {
					objectStore.setState('theme', { success: null, error: null })
					navigationStore.setDialog(false)
				}, 2000)
			})
		},
	},
}
</script>
