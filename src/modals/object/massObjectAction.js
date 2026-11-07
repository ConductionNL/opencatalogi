/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * State and close handling shared by the Mass*Objects dialogs.
 */
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

/** Milliseconds a fully successful dialog stays open before it closes. */
export const AUTO_CLOSE_MS = 2000

/**
 * @spec openspec/specs/generic-object-modals/spec.md
 */
export default {
	data() {
		return {
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
			originalSelectedCount: 0,
		}
	},

	mounted() {
		this.originalSelectedCount = objectStore.selectedObjects?.length || 0
	},

	methods: {
		/**
		 * Close the dialog and cancel a pending auto-close.
		 *
		 * @spec openspec/specs/generic-object-modals/spec.md
		 */
		closeDialog() {
			if (this.closeModalTimeout) {
				clearTimeout(this.closeModalTimeout)
				this.closeModalTimeout = null
			}
			navigationStore.setDialog(false)
		},

		/**
		 * Record the outcome of a mass action.
		 *
		 * Any success marks the dialog done and refreshes the publications; any
		 * failure shows `failedText(count)`. Only a run without failures closes itself.
		 *
		 * @param {{successful: Array, failed: Array}} result The processed objects.
		 * @param {function(number): string} failedText Builds the error from the failed count.
		 * @spec openspec/specs/generic-object-modals/spec.md
		 */
		finishMassAction(result, failedText) {
			const { successful, failed } = result
			if (successful.length > 0) {
				this.success = true
				catalogStore.fetchPublications()
			}
			if (failed.length > 0) {
				this.error = failedText(failed.length)
				return
			}
			if (successful.length > 0) {
				this.closeModalTimeout = setTimeout(() => {
					this.closeDialog()
				}, AUTO_CLOSE_MS)
			}
		},
	},
}
