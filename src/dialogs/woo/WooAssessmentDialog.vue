<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Asks the refusal grounds before a Woo document is saved as partly public
  - or not public (woo-document-assessment-screen, REQ-WDA-002). At least one
  - ground is required; closing the dialog saves nothing.
  -->
<template>
	<NcDialog :name="title" size="normal" @update:open="onOpenChange">
		<div class="woo-assessment-dialog">
			<p v-if="documentName" class="woo-assessment-dialog__subject">
				{{ documentName }}
			</p>
			<NcLoadingIcon v-if="loading" :size="32" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcSelect
				v-else
				v-model="selected"
				:options="grounds"
				:multiple="true"
				keepOpen
				label="label"
				:inputLabel="t('opencatalogi', 'Refusal grounds')"
				:placeholder="t('opencatalogi', 'Choose at least one ground')" />
		</div>
		<template #actions>
			<NcButton @click="close">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="selected.length === 0"
				@click="confirm">
				{{ t('opencatalogi', 'Save assessment') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'

/**
 * A ground row as the select shows it.
 *
 * @param {object} row The ground from the catalogue.
 * @return {{code: string, label: string}} The option.
 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
 */
export function groundOption(row) {
	const code = String(row?.article ?? '')
	const description = String(row?.description ?? '')
	return { code, label: `${code} ${description}`.trim() }
}

export default {
	name: 'WooAssessmentDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The assessment the document moves to: deels_openbaar or niet_openbaar. */
		target: {
			type: String,
			required: true,
		},

		/** The document's file name, shown above the grounds. */
		documentName: {
			type: String,
			default: '',
		},

		/** The grounds the document carries now, preselected. */
		current: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['confirm', 'close'],

	data() {
		return {
			t,
			loading: true,
			error: null,
			grounds: [],
			selected: [],
		}
	},

	computed: {
		/** @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002 */
		title() {
			return this.target === 'niet_openbaar'
				? t('opencatalogi', 'Assess as not public')
				: t('opencatalogi', 'Assess as partly public')
		},
	},

	mounted() {
		this.loadGrounds()
	},

	methods: {
		/**
		 * Load the refusal grounds catalogue and preselect the current ones.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {Promise<void>}
		 */
		async loadGrounds() {
			this.loading = true
			this.error = null
			try {
				const { data } = await axios.get(
					generateUrl('/apps/opencatalogi/api/woo/weigeringsgronden'),
				)
				this.grounds = (data?.results || []).map(groundOption)
				this.selected = this.grounds.filter((g) =>
					this.current.includes(g.code),
				)
			} catch (err) {
				this.error = err.response?.data?.error || err.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Hand the chosen ground codes to the page; nothing without one.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {void}
		 */
		confirm() {
			if (this.selected.length === 0) {
				return
			}
			this.$emit(
				'confirm',
				this.selected.map((g) => g.code),
			)
		},

		/**
		 * Close without saving.
		 *
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
		 * @return {void}
		 */
		close() {
			this.$emit('close')
		},

		/**
		 * The dialog closed itself (Escape or the close button).
		 *
		 * @param {boolean} open Whether it is open.
		 * @spec openspec/changes/woo-document-assessment-screen/specs/woo-transparency/spec.md#requirement-the-batch-page-shows-the-document-queue-and-saves-an-assessment-req-wda-002
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
.woo-assessment-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 160px;
}

.woo-assessment-dialog__subject {
	font-weight: bold;
}
</style>
