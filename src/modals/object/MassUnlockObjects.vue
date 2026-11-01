/** * @file MassUnlockObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2 * @version 1.0.0 */

<template>
	<MassObjectActionDialog
		:title="dialogTitle"
		:confirmLabel="t('opencatalogi', 'Unlock')"
		confirmVariant="warning"
		:confirmDisabled="loading || objectsToUnlock.length === 0"
		:loading="loading"
		:success="success"
		:error="error"
		:successMessage="
			originalSelectedCount > 1
				? t('opencatalogi', '{type}s successfully unlocked', {
						type: t('opencatalogi', 'Publication'),
					})
				: t('opencatalogi', '{type} successfully unlocked', {
						type: t('opencatalogi', 'Publication'),
					})
		"
		@close="closeDialog"
		@confirm="unlockObjects()">
		<NcNoteCard type="warning">
			{{
				t(
					'opencatalogi',
					'Objects will be unlocked and made available for editing by other users. Only objects that are currently locked can be unlocked.',
				)
			}}
		</NcNoteCard>

		<SelectedObjectsList
			:title="
				objectsToUnlock.length === 1
					? t('opencatalogi', 'Publication to Unlock')
					: t('opencatalogi', 'Selected Publications')
			"
			:showRemove="true" />

		<template #icon>
			<LockOpenOutline :size="20" />
		</template>
	</MassObjectActionDialog>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import LockOpenOutline from 'vue-material-design-icons/LockOpenOutline.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'
import MassObjectActionDialog from './MassObjectActionDialog.vue'
import { objectStore } from '../../store/store.js'
import massObjectAction from './massObjectAction.js'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'MassUnlockObjects',
	components: {
		MassObjectActionDialog,
		NcNoteCard,
		SelectedObjectsList,
		LockOpenOutline,
	},

	mixins: [massObjectAction],

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to unlock
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-4 */
		objectsToUnlock() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-4 */
		dialogTitle() {
			const count = this.objectsToUnlock.length
			if (count === 1) {
				return 'Unlock publication'
			}
			return `Unlock ${count} publication${count !== 1 ? 's' : ''}`
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-4 */
		async unlockObjects() {
			this.loading = true

			try {
				const result = await objectStore.massUnlockObjects([
					...this.objectsToUnlock,
				])
				this.finishMassAction(
					result,
					(count) =>
						`Failed to unlock ${count} object${count > 1 ? 's' : ''}`,
				)
			} catch (error) {
				this.success = false
				this.error =
					error.message || 'An error occurred while unlocking objects'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
