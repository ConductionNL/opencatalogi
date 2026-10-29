/** * @file MassLockObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2 * @version 1.0.0 */

<template>
	<MassObjectActionDialog
		:title="dialogTitle"
		:confirmLabel="t('opencatalogi', 'Lock')"
		confirmVariant="primary"
		:confirmDisabled="loading || objectsToLock.length === 0"
		:loading="loading"
		:success="success"
		:error="error"
		:successMessage="
			originalSelectedCount > 1
				? t('opencatalogi', '{type}s successfully locked', {
						type: t('opencatalogi', 'Publication'),
					})
				: t('opencatalogi', '{type} successfully locked', {
						type: t('opencatalogi', 'Publication'),
					})
		"
		@close="closeDialog"
		@confirm="lockObjects()">
		<NcNoteCard type="info">
			{{
				t(
					'opencatalogi',
					"Locking objects prevents other users from modifying them until they are unlocked. You can specify an optional process name to indicate why they're locked and a duration after which they will automatically unlock. Only the user who locked the objects or an administrator can unlock them before the duration expires.",
				)
			}}
		</NcNoteCard>

		<SelectedObjectsList
			:title="
				objectsToLock.length === 1
					? t('opencatalogi', 'Publication to Lock')
					: t('opencatalogi', 'Selected Publications')
			"
			:showRemove="true" />

		<div class="formContainer">
			<NcTextField
				v-model="process"
				:label="t('opencatalogi', 'Process Name (optional)')"
				:disabled="loading" />
			<NcTextField
				v-model="duration"
				type="number"
				:label="t('opencatalogi', 'Duration in seconds (optional)')"
				:disabled="loading" />
		</div>

		<template #icon>
			<LockOutline :size="20" />
		</template>
	</MassObjectActionDialog>
</template>

<script>
import { NcNoteCard, NcTextField } from '@nextcloud/vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'
import MassObjectActionDialog from './MassObjectActionDialog.vue'
import { objectStore } from '../../store/store.js'
import massObjectAction from './massObjectAction.js'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'MassLockObjects',
	components: {
		MassObjectActionDialog,
		NcNoteCard,
		NcTextField,
		SelectedObjectsList,
		LockOutline,
	},

	mixins: [massObjectAction],

	data() {
		return {
			process: '',
			duration: 3600,
		}
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to lock
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-4 */
		objectsToLock() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-4 */
		dialogTitle() {
			const count = this.objectsToLock.length
			if (count === 1) {
				return 'Lock publication'
			}
			return `Lock ${count} publication${count !== 1 ? 's' : ''}`
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-4 */
		async lockObjects() {
			this.loading = true

			try {
				const result = await objectStore.massLockObjects(
					[...this.objectsToLock],
					this.process || null,
					this.duration || null,
				)
				this.finishMassAction(
					result,
					(count) =>
						`Failed to lock ${count} object${count > 1 ? 's' : ''}`,
				)
			} catch (error) {
				this.success = false
				this.error =
					error.message || 'An error occurred while locking objects'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.formContainer {
	margin-top: 1rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
}
</style>
