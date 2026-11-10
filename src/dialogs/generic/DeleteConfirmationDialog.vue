<script setup>
/**
 * Confirm-and-delete dialog for one object type: asks the question, shows
 * the store's progress, success and error for that type, and emits `delete`
 * when the user confirms. The dialog that uses it does the deleting; its
 * other attributes fall through to NcDialog.
 *
 * @module Dialogs
 * @package
 * @license EUPL-1.2
 * @spec openspec/specs/generic-object-modals/spec.md
 */
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { navigationStore, objectStore } from '../../store/store.js'

defineOptions({ name: 'DeleteConfirmationDialog' })

defineProps({
	/** The navigation store's dialog name that opens this dialog. */
	dialog: { type: String, required: true },
	/** The object type whose store state the dialog shows. */
	objectType: { type: String, required: true },
	/** The dialog's title. */
	title: { type: String, required: true },
	/** The text after a successful delete. */
	successText: { type: String, required: true },
	/** The text when the delete did not succeed. */
	failureText: { type: String, required: true },
	/** The text while deleting. */
	loadingText: { type: String, required: true },
	/** The question asked before deleting. */
	question: { type: String, required: true },
})

defineEmits(['delete'])
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === dialog" :name="title">
		<div
			v-if="
				objectStore.getState(objectType).success !== null
				|| objectStore.getState(objectType).error
			">
			<NcNoteCard
				v-if="objectStore.getState(objectType).success"
				type="success">
				<p>{{ successText }}</p>
			</NcNoteCard>
			<NcNoteCard
				v-if="!objectStore.getState(objectType).success"
				type="error">
				<p>{{ failureText }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="objectStore.getState(objectType).error" type="error">
				<p>{{ objectStore.getState(objectType).error }}</p>
			</NcNoteCard>
		</div>
		<div v-if="objectStore.isLoading(objectType)" class="loading-status">
			<NcLoadingIcon :size="20" />
			<span>{{ loadingText }}</span>
		</div>
		<p
			v-if="
				objectStore.getState(objectType).success === null
				&& !objectStore.isLoading(objectType)
			">
			{{ question }}
		</p>
		<template
			v-if="
				objectStore.getState(objectType).success === null
				&& !objectStore.isLoading(objectType)
			"
			#actions>
			<NcButton
				:disabled="objectStore.isLoading(objectType)"
				icon=""
				@click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton
				:disabled="objectStore.isLoading(objectType)"
				icon="Delete"
				variant="error"
				@click="$emit('delete')">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete') }}
			</NcButton>
		</template>
		<template v-else #actions>
			<NcButton icon="" @click="navigationStore.setDialog(false)">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ t('opencatalogi', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style>
.zaakDetailsContainer {
	margin-block-start: var(--OC-margin-20);
	margin-inline-start: var(--OC-margin-20);
	margin-inline-end: var(--OC-margin-20);
}

.success {
	color: green;
}

.loading-status {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	margin: 1rem 0;
	color: var(--color-text-lighter);
}
</style>
