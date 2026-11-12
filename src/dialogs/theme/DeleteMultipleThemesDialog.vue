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
		dialog="deleteMultipleThemes"
		objectType="theme"
		:canClose="false"
		:title="t('opencatalogi', 'Delete Themes')"
		:actionLabel="t('opencatalogi', 'Delete')"
		:actionIcon="Delete"
		actionIconName="Delete"
		actionVariant="error"
		@confirm="deleteThemes()">
		<template #success>
			{{ t('opencatalogi', 'Themes successfully deleted') }}
		</template>
		<template #failure>
			{{
				t(
					'opencatalogi',
					'Something went wrong while deleting the themes',
				)
			}}
		</template>
		<template #loading>
			{{ t('opencatalogi', 'Themes are being deleted...') }}
		</template>
		<template #question>
			{{
				t(
					'opencatalogi',
					'Do you want to delete the selected themes? This action cannot be undone.',
				)
			}}
		</template>
	</ConfirmObjectActionDialog>
</template>

<script>
import ConfirmObjectActionDialog from '../generic/ConfirmObjectActionDialog.vue'

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
		ConfirmObjectActionDialog,
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
