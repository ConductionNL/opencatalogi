<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="open"
		:name="name"
		@update:open="(value) => !value && $emit('close')">
		<p class="reason-dialog__hint">
			{{ hint }}
		</p>
		<NcTextArea
			v-model="reason"
			:label="t('opencatalogi', 'Reason')"
			:disabled="busy"
			:data-testid="testid + '-reason'"
			required />
		<template #actions>
			<NcButton
				:data-testid="testid + '-cancel'"
				:disabled="busy"
				@click="$emit('close')">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || reason.trim() === ''"
				:data-testid="testid + '-confirm'"
				@click="confirm">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextArea } from '@nextcloud/vue'

/**
 * Asks for the reason of one move on a publication, and hands the trimmed
 * reason to whoever opened it: putting a withdrawn document back
 * (REQ-ATT-104), lifting an embargo (REQ-PSG-002). The confirm button stays
 * disabled until a reason is given, and the reason starts empty each time the
 * dialog opens. The dialog calls nothing itself.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.2
 */
export default {
	name: 'ReasonDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcTextArea },

	props: {
		open: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		/** The dialog's title. */
		name: { type: String, required: true },
		/** What the move does, shown above the reason. */
		hint: { type: String, default: '' },
		/** The confirm button's label. */
		confirmLabel: { type: String, required: true },
		/** Prefix of the reason, cancel and confirm test ids. */
		testid: { type: String, default: 'reason' },
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
		 * Hand the trimmed reason to the caller.
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
.reason-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 12px;
}
</style>
