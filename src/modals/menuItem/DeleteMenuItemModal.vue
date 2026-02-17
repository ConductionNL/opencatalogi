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
		:name="'Delete menu item'"
		@close="handleCancel">
		<div class="modal__content">
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
				<p>Are you sure you want to delete the menu item '{{ menuItem.title || menuItem.name }}'?</p>
			</div>

			<div class="modalActions">
				<NcButton
					class="modalCloseButton"
					@click="handleCancel">
					<template #icon>
						<Cancel :size="20" />
					</template>
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
			</div>
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
import { Menu } from '../../entities/index.js'

// icons
import Delete from 'vue-material-design-icons/Delete.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

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
const menuItem = computed(() => objectStore.getActiveObject('menuItem') || {})

/**
 * Handle delete action
 * Remove the item from the active menu and update the menu object
 * @return {Promise<void>}
 */
const handleDelete = async () => {
	loading.value = true
	try {
		const menu = objectStore.getActiveObject('menu')
		if (!menu?.id) throw new Error('No active menu')

		// Use the same logic as MenuItemForm.vue to find and remove the correct item
		const activeMenuItem = menuItem.value
		let itemIndex = -1

		// Try to find by index first (most reliable)
		if (activeMenuItem.index !== undefined && activeMenuItem.index >= 0 && activeMenuItem.index < menu.items.length) {
			itemIndex = activeMenuItem.index
		} else {
			// Try to find by id
			if (activeMenuItem.id && activeMenuItem.id !== null && activeMenuItem.id !== undefined) {
				itemIndex = menu.items.findIndex(item => item.id === activeMenuItem.id)
			}

			// If still not found, try by name and order
			if (itemIndex === -1) {
				itemIndex = menu.items.findIndex(item =>
					item.name === activeMenuItem.name
					&& item.order === activeMenuItem.order
				)
			}
		}

		// Create updated items array by removing the item at the found index
		const updatedItems = [...(menu.items || [])]
		if (itemIndex !== -1 && itemIndex < updatedItems.length) {
			updatedItems.splice(itemIndex, 1)
		} else {
			throw new Error('Could not find menu item to delete')
		}
		const newMenu = new Menu({ ...menu, items: updatedItems })
		await objectStore.updateObject('menu', newMenu.id, newMenu)
		success.value = true
		EventBus.$emit('delete-menu-item-item-success')
		setTimeout(() => {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('menuItem')
		}, 2000)
	} catch (e) {
		console.error('Error deleting menu item:', e)
		success.value = false
		error.value = e.message
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
	objectStore.clearActiveObject('menuItem')
}

export default {
	name: 'DeleteMenuItemModal',
	components: {
		NcModal,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		Cancel,
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
