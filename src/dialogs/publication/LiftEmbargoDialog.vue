<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="open"
		:name="t('opencatalogi', 'Lift embargo')"
		data-testid="lift-embargo-dialog"
		@update:open="(value) => !value && $emit('close')">
		<p class="lift-embargo-dialog__hint">
			{{
				t(
					'opencatalogi',
					'Publish "{name}" now, before its publication date? The lift is recorded in the audit trail with your name and the reason.',
					{ name: title },
				)
			}}
		</p>
		<NcTextArea
			v-model="reason"
			:label="t('opencatalogi', 'Reason')"
			:disabled="busy"
			data-testid="lift-embargo-reason"
			required />
		<template #actions>
			<NcButton
				data-testid="lift-embargo-cancel"
				:disabled="busy"
				@click="$emit('close')">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || reason.trim() === ''"
				data-testid="lift-embargo-confirm"
				@click="confirm">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Lift embargo') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextArea } from '@nextcloud/vue'

/**
 * Asks for the reason an embargo is lifted before a scheduled publication is
 * published early (REQ-PSG-002).
 *
 * The dialog only collects the reason; the widget that opened it calls the
 * server, which checks the lift right again and logs the lift.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.2
 */
export default {
	name: 'LiftEmbargoDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcTextArea },

	props: {
		open: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		/** The publication's title, named in the question. */
		title: { type: String, default: '' },
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			reason: '',
		}
	},

	watch: {
		/**
		 * Start empty every time the dialog opens.
		 *
		 * @param {boolean} value Whether the dialog is open.
		 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.2
		 */
		open(value) {
			if (value) {
				this.reason = ''
			}
		},
	},

	methods: {
		/**
		 * Hand the trimmed reason to the widget.
		 *
		 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.2
		 */
		confirm() {
			this.$emit('confirm', { reason: this.reason.trim() })
		},
	},
}
</script>

<style scoped>
.lift-embargo-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 12px;
}
</style>
