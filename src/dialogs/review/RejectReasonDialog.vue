<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Asks the reason before a Woo batch in review is rejected, or one of its
  - documents is sent back (woo-review-surface, REQ-WRV-002). The reason is
  - required; closing the dialog rejects nothing.
  -->
<template>
	<NcDialog :name="title" size="normal" @update:open="onOpenChange">
		<div class="woo-reject-dialog">
			<p v-if="subject" class="woo-reject-dialog__subject">
				{{ subject }}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('opencatalogi', 'Reason')"
				:placeholder="
					t(
						'opencatalogi',
						'What has to change before this can be published',
					)
				"
				resize="vertical" />
		</div>
		<template #actions>
			<NcButton @click="close">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="trimmed === ''" @click="confirm">
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'RejectReasonDialog',
	components: {
		NcButton,
		NcDialog,
		NcTextArea,
	},

	props: {
		/** What goes back: the whole batch, or one document. */
		scope: {
			type: String,
			default: 'batch',
			validator: (value) => ['batch', 'document'].includes(value),
		},

		/** The batch or document name, shown above the reason. */
		subject: {
			type: String,
			default: '',
		},
	},

	emits: ['confirm', 'close'],

	data() {
		return {
			t,
			reason: '',
		}
	},

	computed: {
		/** @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002 */
		trimmed() {
			return this.reason.trim()
		},

		/** @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002 */
		title() {
			return this.scope === 'document'
				? t('opencatalogi', 'Send the document back')
				: t('opencatalogi', 'Reject the batch')
		},

		/** @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002 */
		confirmLabel() {
			return this.scope === 'document'
				? t('opencatalogi', 'Send back')
				: t('opencatalogi', 'Reject')
		},
	},

	methods: {
		/**
		 * Hand the reason to the page; nothing without one.
		 *
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
		 * @return {void}
		 */
		confirm() {
			if (this.trimmed === '') {
				return
			}
			this.$emit('confirm', this.trimmed)
		},

		/**
		 * Close without rejecting.
		 *
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
		 * @return {void}
		 */
		close() {
			this.$emit('close')
		},

		/**
		 * The dialog closed itself (escape, the close button).
		 *
		 * @param {boolean} open Whether it is open.
		 * @spec openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#requirement-a-rejected-batch-or-document-goes-back-to-its-author-with-the-reason-req-wrv-002
		 * @return {void}
		 */
		onOpenChange(open) {
			if (!open) {
				this.close()
			}
		},
	},
}
</script>

<style scoped>
.woo-reject-dialog {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-bottom: 8px;
}

.woo-reject-dialog__subject {
	font-weight: bold;
	overflow-wrap: anywhere;
}
</style>
