/** * @file MassValidateObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2 * @version 1.0.0 */

<template>
	<MassObjectActionDialog
		:title="dialogTitle"
		:confirmLabel="t('opencatalogi', 'Validate')"
		confirmVariant="primary"
		:confirmDisabled="loading || objectsToValidate.length === 0"
		:loading="loading"
		:success="success"
		:error="error"
		:successMessage="
			originalSelectedCount > 1
				? t('opencatalogi', '{type}s successfully validated', {
						type: t('opencatalogi', 'Publication'),
					})
				: t('opencatalogi', '{type} successfully validated', {
						type: t('opencatalogi', 'Publication'),
					})
		"
		@close="closeDialog"
		@confirm="validateObjects()">
		<NcNoteCard type="info">
			<strong>{{ t('opencatalogi', 'When to use mass validation:') }}</strong
			><br />
			{{
				t(
					'opencatalogi',
					'• After updating the schema to apply new validation rules',
				)
			}}<br />
			{{
				t(
					'opencatalogi',
					'• When publications need to be re-enriched with updated name/description logic',
				)
			}}<br />
			{{
				t(
					'opencatalogi',
					'• To refresh computed properties or auto-generated fields',
				)
			}}<br />
			{{
				t(
					'opencatalogi',
					'• After changing schema configuration that affects existing publications',
				)
			}}<br /><br />
			{{
				t(
					'opencatalogi',
					'Publications will be saved without modification to trigger validation and enrichment processes against the current schema.',
				)
			}}
		</NcNoteCard>

		<SelectedObjectsList
			:title="
				objectsToValidate.length === 1
					? t('opencatalogi', 'Publication to Validate')
					: t('opencatalogi', 'Selected Publications')
			"
			:showRemove="true" />

		<template #icon>
			<CheckCircle :size="20" />
		</template>
	</MassObjectActionDialog>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'
import MassObjectActionDialog from './MassObjectActionDialog.vue'
import { objectStore } from '../../store/store.js'
import massObjectAction from './massObjectAction.js'

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	name: 'MassValidateObjects',
	components: {
		MassObjectActionDialog,
		NcNoteCard,
		SelectedObjectsList,
		CheckCircle,
	},

	mixins: [massObjectAction],

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to validate
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-5 */
		objectsToValidate() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 */
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-5 */
		dialogTitle() {
			const count = this.objectsToValidate.length
			if (count === 1) {
				return 'Validate publication'
			}
			return `Validate ${count} publication${count !== 1 ? 's' : ''}`
		},
	},

	methods: {
		/** @spec openspec/changes/retrofit-2026-05-26-mass-object-actions/tasks.md#task-5 */
		async validateObjects() {
			this.loading = true

			try {
				const result = await objectStore.massValidateObjects([
					...this.objectsToValidate,
				])
				this.finishMassAction(
					result,
					(count) =>
						`Failed to validate ${count} object${count > 1 ? 's' : ''}`,
				)
			} catch (error) {
				this.success = false
				this.error =
					error.message || 'An error occurred while validating objects'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
