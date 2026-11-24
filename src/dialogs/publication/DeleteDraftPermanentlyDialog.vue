<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcDialog
		:open="open"
		:name="t('opencatalogi', 'Delete permanently')"
		data-testid="delete-draft-dialog"
		@update:open="(value) => !value && $emit('close')">
		<p class="delete-draft-dialog__question">
			{{
				t(
					'opencatalogi',
					'Delete "{name}" permanently? This cannot be undone.',
					{ name: title },
				)
			}}
		</p>
		<p class="delete-draft-dialog__hint">
			{{
				t(
					'opencatalogi',
					'Its files, its document references and the records that point at it go with it. It cannot be restored.',
				)
			}}
		</p>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-testid="delete-draft-confirm"
				@click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete permanently') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'

/**
 * Asks to confirm that a draft, or a publication in review, that was never
 * published is deleted permanently with everything on it (REQ-PLC-004).
 *
 * The dialog only asks; the widget that opened it calls the server.
 *
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/tasks.md#task-1.2
 */
export default {
	name: 'DeleteDraftPermanentlyDialog',
	components: { NcButton, NcDialog, NcLoadingIcon },

	props: {
		open: { type: Boolean, default: false },
		busy: { type: Boolean, default: false },
		/** The publication's title, named in the question. */
		title: { type: String, default: '' },
	},

	emits: ['close', 'confirm'],
}
</script>

<style scoped>
.delete-draft-dialog__question {
	margin-block-end: 8px;
}

.delete-draft-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin-block-end: 12px;
}
</style>
