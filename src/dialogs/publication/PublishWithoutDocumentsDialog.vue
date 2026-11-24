<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="open"
		:name="t('opencatalogi', 'Publish without documents')"
		data-testid="publish-without-documents-dialog"
		@update:open="(value) => !value && $emit('close')">
		<NcNoteCard type="warning" data-testid="publish-without-documents-warning">
			{{
				t(
					'opencatalogi',
					'"{name}" has no documents: no files and no document references. Readers will find the publication but nothing to open.',
					{ name: title },
				)
			}}
		</NcNoteCard>
		<NcCheckboxRadioSwitch
			v-model="confirmed"
			:disabled="busy"
			data-testid="publish-without-documents-tick">
			{{ t('opencatalogi', 'Publish it without documents') }}
		</NcCheckboxRadioSwitch>
		<template #actions>
			<NcButton
				data-testid="publish-without-documents-cancel"
				:disabled="busy"
				@click="$emit('close')">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="publish-without-documents-confirm"
				variant="primary"
				:disabled="busy || !confirmed"
				@click="$emit('confirm')">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('opencatalogi', 'Publish') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * Asks a second time before a publication with no documents is published:
 * a warning, and a tick the editor must set before Publish is enabled
 * (REQ-PSG-004). Opened when the publish route refuses with 409
 * `no-documents`; the widget that opened it sends the publish again with
 * `confirmNoDocuments: true`.
 *
 * The tick is cleared each time the dialog opens, so a confirmation never
 * carries over to another publish.
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
 */
export default {
	name: 'PublishWithoutDocumentsDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon, NcNoteCard },

	props: {
		open: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		/** The publication's title, named in the warning. */
		title: { type: String, default: '' },
	},

	emits: ['close', 'confirm'],

	data() {
		return { confirmed: false }
	},

	watch: {
		/**
		 * Clear the tick when the dialog opens.
		 *
		 * @param {boolean} value Whether the dialog is open.
		 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-4.2
		 */
		open(value) {
			if (value) {
				this.confirmed = false
			}
		},
	},
}
</script>
