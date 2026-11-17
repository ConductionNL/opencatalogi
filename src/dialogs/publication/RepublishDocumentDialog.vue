<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="open"
		:name="t('opencatalogi', 'Put a document back')"
		data-testid="republish-document-dialog"
		@update:open="(value) => !value && $emit('close')">
		<p class="republish-dialog__hint">
			{{
				t(
					'opencatalogi',
					'{document} becomes public again on the public site and in the sitemap.',
					{ document: document ? document.label : '' },
				)
			}}
		</p>
		<NcTextArea
			v-model="reason"
			:label="t('opencatalogi', 'Reason')"
			:disabled="busy"
			data-testid="republish-reason"
			required />
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || reason.trim() === ''"
				data-testid="republish-confirm"
				@click="confirm">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
				</template>
				{{ t('opencatalogi', 'Republish') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextArea } from '@nextcloud/vue'

/**
 * Asks why a withdrawn document is put back (REQ-ATT-104).
 *
 * The dialog only collects the reason; the widget that opened it calls the
 * server.
 */
export default {
	name: 'RepublishDocumentDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcTextArea },

	props: {
		open: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		/** The withdrawn document, as `{ id, label }`. */
		document: { type: Object, default: null },
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
		 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
		 */
		open(value) {
			if (value) {
				this.reason = ''
			}
		},
	},

	methods: {
		/**
		 * Hand the reason to the widget.
		 *
		 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
		 */
		confirm() {
			this.$emit('confirm', { reason: this.reason.trim() })
		},
	},
}
</script>

<style scoped>
.republish-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 12px;
}
</style>
