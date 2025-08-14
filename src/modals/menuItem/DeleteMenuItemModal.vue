/**
 * DeleteMenuItemModal.vue
 * Modal for deleting menu items
 * @category Components
 * @package opencatalogi
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/opencatalogi
 */

<script setup>
import { ref, computed } from 'vue'
import { objectStore, navigationStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<NcModal v-if="navigationStore.modal === 'deleteMenuItem'"
		ref="modalRef"
		class="deleteMenuItemModal"
		label-id="deleteMenuItemModal"
		@close="handleCancel">
		<div class="modal__content">
			<h2>Delete menu item</h2>
			<div v-if="success !== null || error">
				<NcNoteCard v-if="success" type="success">
					<p>Menu item successfully deleted</p>
				</NcNoteCard>
				<NcNoteCard v-if="!success" type="error">
					<p>Something went wrong while deleting the menu item</p>
				</NcNoteCard>
				<NcNoteCard v-if="error" type="error">
					<p>{{ error }}</p>
				</NcNoteCard>
			</div>
			<div v-if="success === null" class="form-group">
				<p>Are you sure you want to delete the menu item '{{ menuItem.title }}'?</p>
			</div>

			<span class="buttonContainer">
				<NcButton
					@click="handleCancel">
					{{ success ? 'Close' : 'Cancel' }}
				</NcButton>
				<NcButton v-if="success === null"
					:disabled="loading"
					type="error"
					@click="handleDelete">
					<template #icon>
						<span>
							<NcLoadingIcon v-if="loading" :size="20" />
							<Delete v-if="!loading" :size="20" />
						</span>
					</template>
					Delete
				</NcButton>
			</span>
		</div>
	</NcModal>
</template>

<script>
import {
	NcButton,
	NcModal,
	NcNoteCard,
	NcLoadingIcon,
} from '@nextcloud/vue'

// icons
import Delete from 'vue-material-design-icons/Delete.vue'

/**
 * Loading state for the component
 * @type {import('vue').Ref<boolean>}
 */
const loading = ref(false)

/**
 * Success state for the component
 * @type {import('vue').Ref<boolean|null>}
 */
const success = ref(null)

/**
 * Error state for the component
 * @type {import('vue').Ref<string|null>}
 */
const error = ref(null)

/**
 * Get the active menu item from the store
 * @return {object | null}
 */
const menuItem = computed(() => objectStore.getActiveObject('menuItem'))

/**
 * Handle delete action
 * @return {Promise<void>}
 */
const handleDelete = async () => {
	loading.value = true
	try {
		await objectStore.deleteObject('menuItem', menuItem.value.id)
		success.value = true
		success.value = true
		EventBus.$emit('delete-menu-item-item-success')
	} catch (error) {
		console.error('Error deleting menu item:', error)
		success.value = false
		error.value = error.message
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

export default {
	name: 'DeleteMenuItemModal',
	components: {
		NcModal,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
	},
	data() {
		return {
			loading: false,
			success: null,
			error: null,
		}
	},
	methods: {
		closeModal() {
			this.navigationStore.setModal(false)
		},
	},
}
</script>

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
