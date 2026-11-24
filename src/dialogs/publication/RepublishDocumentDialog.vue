<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<ReasonDialog
		:open="open"
		:busy="busy"
		:name="t('opencatalogi', 'Put a document back')"
		:hint="t('opencatalogi', '{document} becomes public again on the public site and in the sitemap.', { document: document ? document.label : '' })"
		:confirmLabel="t('opencatalogi', 'Republish')"
		testid="republish"
		data-testid="republish-document-dialog"
		@close="$emit('close')"
		@confirm="(answer) => $emit('confirm', answer)" />
</template>

<script>
import ReasonDialog from './ReasonDialog.vue'

/**
 * Asks why a withdrawn document is put back (REQ-ATT-104).
 *
 * The dialog only collects the reason; the widget that opened it calls the
 * server.
 *
 * @spec openspec/changes/attachments-are-files/specs/publication-attachments/spec.md#requirement-a-withdrawn-attachment-stays-withdrawn-req-att-104
 */
export default {
	name: 'RepublishDocumentDialog',
	components: { ReasonDialog },

	props: {
		open: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		/** The withdrawn document, as `{ id, label }`. */
		document: { type: Object, default: null },
	},

	emits: ['close', 'confirm'],
}
</script>
