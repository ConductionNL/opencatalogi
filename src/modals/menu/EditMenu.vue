<script setup>
import { menuStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'editMenu'"
		:name="menuStore.menuItem?.id ? 'Edit Menu' : 'Add Menu'"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>Menu successfully modified</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton
				@click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="loading"
				type="primary"
				@click="editMenu()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<ContentSaveOutline v-if="!loading && menuStore.menuItem?.id" :size="20" />
					<Plus v-if="!loading && !menuStore.menuItem?.id" :size="20" />
				</template>
				{{ menuStore.menuItem?.id ? 'Save' : 'Add' }}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<NcTextArea :disabled="loading"
				label="Menu"
				placeholder="Menu content"
				:value.sync="menuItem.content" />
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextArea,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

/**
 * Component for editing menu items
 */
export default {
	name: 'EditMenu',
	components: {
		NcDialog,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		ContentSaveOutline,
		Cancel,
		Plus,
	},
	data() {
		return {
			menuItem: {
				content: '',
			},
			success: null,
			loading: false,
			error: false,
			hasUpdated: false,
			closeDialogTimeout: null,
		}
	},
	mounted() {
		this.initializeMenuItem()
	},
	updated() {
		if (navigationStore.dialog === 'editMenu' && !this.hasUpdated) {
			this.initializeMenuItem()
			this.hasUpdated = true
		}
	},
	methods: {
		/**
		 * Initialize menu item data from store
		 */
		initializeMenuItem() {
			if (menuStore.menuItem?.id) {
				this.menuItem = {
					...menuStore.menuItem,
					content: menuStore.menuItem.content || '',
				}
			}
		},
		/**
		 * Close the dialog and reset state
		 */
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeDialogTimeout)
			this.success = null
			this.loading = false
			this.error = false
			this.hasUpdated = false
			this.menuItem = {
				content: '',
			}
		},
		/**
		 * Save menu item changes
		 */
		async editMenu() {
			this.loading = true

			menuStore.saveMenu({
				...this.menuItem,
			}).then(({ response }) => {
				this.success = response.ok
				this.error = false
				response.ok && (this.closeDialogTimeout = setTimeout(this.closeDialog, 2000))
			}).catch((error) => {
				this.success = false
				this.error = error.message || 'An error occurred while saving the menu'
			}).finally(() => {
				this.loading = false
			})
		},
	},
}
</script>
