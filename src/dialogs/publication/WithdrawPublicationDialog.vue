<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="open"
		:name="
			document
				? t('opencatalogi', 'Withdraw a document')
				: t('opencatalogi', 'Withdraw publication')
		"
		data-testid="withdraw-publication-dialog"
		@update:open="(value) => !value && $emit('close')">
		<p class="withdraw-dialog__hint">
			{{
				document
					? t(
							'opencatalogi',
							'The document leaves the public site and the sitemap at once. The rest of the publication stays public.',
						)
					: t(
							'opencatalogi',
							'The publication leaves the public site at once, and every national channel it reached is told to take it down.',
						)
			}}
		</p>
		<NcSelect
			v-if="documents.length > 0"
			v-model="document"
			:options="documents"
			label="label"
			:inputLabel="
				t(
					'opencatalogi',
					'Document (leave empty to withdraw the whole publication)',
				)
			"
			:disabled="busy"
			data-testid="withdraw-document-select" />
		<NcTextArea
			v-model="reason"
			:label="t('opencatalogi', 'Reason')"
			:disabled="busy"
			data-testid="withdraw-reason"
			required />
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy || reason.trim() === ''"
				data-testid="withdraw-confirm"
				@click="confirm">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
				</template>
				{{ t('opencatalogi', 'Withdraw') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcSelect,
	NcTextArea,
} from '@nextcloud/vue'

/**
 * Asks for the reason a publication, or one of its documents, is withdrawn.
 *
 * The dialog only collects the answer; the widget that opened it calls the
 * server, so the dialog stays the same for both moves.
 */
export default {
	name: 'WithdrawPublicationDialog',
	components: { NcButton, NcDialog, NcLoadingIcon, NcSelect, NcTextArea },

	props: {
		open: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		/** The publication's documents, as `{ id, label }` options. */
		documents: { type: Array, default: () => [] },
	},

	emits: ['close', 'confirm'],

	data() {
		return {
			reason: '',
			document: null,
		}
	},

	watch: {
		/**
		 * Start empty every time the dialog opens.
		 *
		 * @param {boolean} value Whether the dialog is open.
		 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
		 */
		open(value) {
			if (value) {
				this.reason = ''
				this.document = null
			}
		},
	},

	methods: {
		/**
		 * Hand the reason, and the document when one was chosen, to the widget.
		 *
		 * @spec openspec/specs/publications/spec.md#requirement-an-editor-publishes-or-withdraws-a-publication-in-one-action-req-ppw-002
		 */
		confirm() {
			this.$emit('confirm', {
				reason: this.reason.trim(),
				fileId: this.document ? String(this.document.id) : '',
			})
		},
	},
}
</script>

<style scoped>
.withdraw-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 12px;
}
</style>
