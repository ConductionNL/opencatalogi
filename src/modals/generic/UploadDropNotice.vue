<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div v-if="success !== null || error || duplicateWarning">
		<NcNoteCard v-if="success" type="success">
			<p>
				{{ t('opencatalogi', 'Files added successfully') }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error && !success" type="error">
			<p>
				{{
					t(
						'opencatalogi',
						'Something went wrong when adding files',
					)
				}}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error && !success" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="duplicateWarning" type="warning">
			<p>{{ duplicateWarning }}</p>
		</NcNoteCard>
		<div v-if="false">
			<NcNoteCard type="error">
				<p>
					{{
						t(
							'opencatalogi',
							'Select files with the correct extension',
						)
					}}
				</p>
			</NcNoteCard>
		</div>
	</div>
	<div
		class="filesListDragDropNoticeWrapper"
		:class="{
			'filesListDragDropNoticeWrapper--disabled':
				!labelCount
				|| loading
				|| retryLoading,
		}">
		<div class="filesListDragDropNoticeWrapperIcon">
			<TrayArrowDown :size="48" />
			<h3 class="filesListDragDropNoticeTitle">
				{{ t('opencatalogi', 'Drag a file or files here') }}
			</h3>
		</div>

		<h3 class="filesListDragDropNoticeTitle">
			{{ t('opencatalogi', 'Or') }}
		</h3>

		<div class="filesListDragDropNoticeTitle">
			<NcButton
				:disabled="
					loading
					|| retryLoading
					|| !labelCount
				"
				variant="primary"
				@click="$emit('openFileUpload')">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('opencatalogi', 'Add a file or files') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'

/**
 * UploadDropNotice — the upload dialog's result notes and its drop-or-add
 * prompt, shown both before and after labels are chosen.
 *
 * @spec openspec/specs/file-management/spec.md
 */
export default {
	name: 'UploadDropNotice',
	components: {
		NcButton,
		NcNoteCard,
		Plus,
		TrayArrowDown,
	},

	props: {
		/** Whether the last upload succeeded; null before any upload */
		success: {
			type: [Boolean, String],
			default: null,
		},

		/** The last upload error, false without one */
		error: {
			type: [Boolean, String, Object],
			default: false,
		},

		/** The duplicate-files warning, null without one */
		duplicateWarning: {
			type: String,
			default: null,
		},

		/** How many labels are chosen */
		labelCount: {
			type: Number,
			default: undefined,
		},

		/** Whether files are uploading */
		loading: {
			type: Boolean,
			default: false,
		},

		/** Whether failed files are being retried */
		retryLoading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['openFileUpload'],
}
</script>

<style scoped>
.filesListDragDropNoticeWrapper--disabled {
	opacity: 0.4;
}
</style>
