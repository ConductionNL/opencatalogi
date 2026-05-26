<script setup>
/**
 * EditAttachmentModal — update attachment metadata via updateObject('attachment').
 *
 * @spec openspec/changes/retrofit-2026-05-25-file-management/tasks.md#task-3
 */
import { translate as t } from '@nextcloud/l10n'
import { ref, computed } from 'vue'
import { objectStore, navigationStore } from '../../store/store.js'
import { NcButton, NcModal, NcTextField, NcSelectTags, NcCheckboxRadioSwitch, NcNoteCard, NcLoadingIcon } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'

/**
 * Loading state for the component
 * @type {import('vue').Ref<boolean>}
 */
const loading = ref(false)

/**
 * Success state for the component: null = form, true = saved, false = failed
 * @type {import('vue').Ref<boolean|null>}
 */
const success = ref(null)

/**
 * Error message from the failed save, if any
 * @type {import('vue').Ref<string|null>}
 */
const errorMessage = ref(null)

/**
 * Get the active attachment from the store
 * @return {object | null}
 */
const attachment = computed(() => objectStore.getActiveObject('attachment'))

/**
 * Handle save action
 * @return {Promise<void>}
 */
const handleSave = async () => {
	loading.value = true
	errorMessage.value = null
	try {
		await objectStore.updateObject('attachment', attachment.value.id, attachment.value)
		await objectStore.fetchCollection('attachment')
		success.value = true
	} catch (err) {
		console.error('Error updating attachment:', err)
		success.value = false
		errorMessage.value = err.message
	} finally {
		loading.value = false
	}
}

/**
 * Handle cancel action
 * @return {void}
 */
const handleCancel = () => {
	navigationStore.setModal(false)
}
</script>

<template>
	<NcModal
		ref="modalRef"
		class="editAttachmentModal"
		label-id="editAttachmentModal"
		@close="handleCancel">
		<div class="modal__content">
			<h2>{{ t('opencatalogi', 'Edit Attachment') }}</h2>
			<div v-if="success !== null || errorMessage">
				<NcNoteCard v-if="success" type="success">
					<p>{{ t('opencatalogi', 'Attachment successfully updated') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="success === false" type="error">
					<p>{{ t('opencatalogi', 'Something went wrong while updating the attachment') }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="errorMessage" type="error">
					<p>{{ errorMessage }}</p>
				</NcNoteCard>
			</div>
			<div v-if="success === null && attachment" class="form-group">
				<NcTextField
					:value.sync="attachment.title"
					:label="t('opencatalogi', 'Title')"
					:disabled="loading" />
				<NcTextField
					:value.sync="attachment.description"
					:label="t('opencatalogi', 'Description')"
					:disabled="loading" />
				<NcSelectTags
					v-model="attachment.tags"
					label="Tags"
					:aria-label-combobox="t('opencatalogi', 'Tags')"
					:disabled="loading" />
				<NcCheckboxRadioSwitch
					:checked.sync="attachment.published"
					:disabled="loading">
					{{ t('opencatalogi', 'Published') }}
				</NcCheckboxRadioSwitch>
			</div>

			<span class="buttonContainer">
				<NcButton @click="handleCancel">
					{{ success ? t('opencatalogi', 'Close') : t('opencatalogi', 'Cancel') }}
				</NcButton>
				<NcButton v-if="success === null"
					:disabled="loading"
					type="primary"
					@click="handleSave">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ t('opencatalogi', 'Save') }}
				</NcButton>
			</span>
		</div>
	</NcModal>
</template>

<style scoped>
.modal__content {
	padding: 20px;
}

.buttonContainer {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-top: 20px;
}
</style>
