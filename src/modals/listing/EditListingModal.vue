/** * EditListingModal.vue * Modal for editing a listing * @category Components *
@package opencatalogi * @author Ruben Linde * @copyright 2024 * @license EUPL-1.2 *
@version 1.0.0 * @link https://github.com/opencatalogi/opencatalogi * * @spec
openspec/specs/dashboard/spec.md */

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcInputField, NcSelectTags } from '@nextcloud/vue'
import { computed, ref } from 'vue'
import { navigationStore, objectStore } from '../../store/store.js'

/**
 * Loading state for the component
 *
 * @type {import('vue').Ref<boolean>}
 */
const loading = ref(false)

/**
 * Get the active directory from the store
 *
 * @return {object | null}
 */
const directory = computed(() => objectStore.getActiveObject('directory'))

/**
 * Handle save action
 *
 * @return {Promise<void>}
 */
async function handleSave() {
	loading.value = true
	try {
		await objectStore.updateObject('directory', directory.value)
		await objectStore.fetchCollection('directory')
		navigationStore.setModal(false)
	} catch (error) {
		console.error('Error saving directory:', error)
	} finally {
		loading.value = false
	}
}

/**
 * Handle cancel action
 *
 * @return {void}
 */
function handleCancel() {
	navigationStore.setModal(false)
}
</script>

<template>
	<div class="edit-listing-modal">
		<NcInputField
			v-model="directory.title"
			:label="t('opencatalogi', 'Title')"
			:disabled="loading" />
		<NcInputField
			v-model="directory.summary"
			:label="t('opencatalogi', 'Summary')"
			:disabled="loading" />
		<NcInputField
			v-model="directory.description"
			:label="t('opencatalogi', 'Description')"
			:disabled="loading" />
		<NcSelectTags
			v-model="directory.labels"
			:inputLabel="t('opencatalogi', 'Labels')"
			:aria-label-combobox="t('opencatalogi', 'Labels')"
			:disabled="loading" />
		<div class="edit-listing-modal__actions">
			<NcButton :disabled="loading" @click="handleCancel">
				{{ t('opencatalogi', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="loading" @click="handleSave">
				{{ t('opencatalogi', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.edit-listing-modal {
	padding: 20px;
}

.edit-listing-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
}
</style>
