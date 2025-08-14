<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { createZodErrorHandler } from '../../services/formatZodErrors.js'
import { EventBus } from '../../eventBus.js'
import { getNextcloudGroups } from '../../services/nextcloudGroups.js'
</script>

<template>
	<NcModal ref="modalRef"
		:label-id="isEdit ? 'editMenuItem' : 'addMenuItem'"
		@close="closeModal">
		<div class="modal__content">
			<h2>{{ isEdit ? 'Edit Menu Item' : 'Add Menu Item' }}</h2>

			<div v-if="objectStore.getState('menu').success !== null || objectStore.getState('menu').error">
				<NcNoteCard v-if="objectStore.getState('menu').success" type="success">
					<p>Menu item successfully {{ isEdit ? 'edited' : 'added' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="objectStore.getState('menu').error" type="error">
					<p>{{ objectStore.getState('menu').error }}</p>
				</NcNoteCard>
			</div>

			<div v-if="objectStore.getState('menu').success === null" class="form-container">
				<NcTextField
					:disabled="objectStore.isLoading('menu')"
					label="Order"
					type="number"
					min="0"
					:value.sync="menuItem.order"
					:error="!!inputValidation.getError(`items.${index}.order`)"
					:helper-text="inputValidation.getError(`items.${index}.order`)"
					@update:value="handleOrderUpdate" />

				<NcTextField
					:disabled="objectStore.isLoading('menu')"
					label="Name"
					:value.sync="menuItem.name"
					:error="!!inputValidation.getError(`items.${index}.name`)"
					:helper-text="inputValidation.getError(`items.${index}.name`)" />

				<NcTextField
					:disabled="objectStore.isLoading('menu')"
					label="Description"
					:value.sync="menuItem.description"
					:error="!!inputValidation.getError(`items.${index}.description`)"
					:helper-text="inputValidation.getError(`items.${index}.description`)" />

				<NcTextField
					:disabled="objectStore.isLoading('menu')"
					label="Slug"
					:value.sync="menuItem.slug"
					:error="!!inputValidation.getError(`items.${index}.slug`)"
					:helper-text="inputValidation.getError(`items.${index}.slug`)" />

				<NcTextField
					:disabled="objectStore.isLoading('menu')"
					label="Link"
					:value.sync="menuItem.link"
					:error="!!inputValidation.getError(`items.${index}.link`)"
					:helper-text="inputValidation.getError(`items.${index}.link`)" />

				<NcSelect v-bind="iconOptions"
					v-model="iconOptions.value"
					input-label="Icon"
					:disabled="objectStore.isLoading('menu')" />

				<div class="groups-section">
					<label class="groups-label">Groups</label>
					<NcSelect
						v-model="groupsOptions.value"
						:disabled="objectStore.isLoading('menu') || groupsOptions.loading"
						multiple
						:options="groupsOptions.options" />
					<p v-if="groupsOptions.loading" class="groups-loading">
						Loading groups...
					</p>
				</div>

				<div class="groups-refresh">
					<NcButton
						:disabled="groupsOptions.loading"
						type="secondary"
						size="small"
						@click="fetchGroups">
						<template #icon>
							<Refresh v-if="!groupsOptions.loading" :size="16" />
							<NcLoadingIcon v-else :size="16" />
						</template>
						{{ groupsOptions.loading ? 'Loading...' : 'Refresh Groups' }}
					</NcButton>
				</div>

				<div class="hide-after-login">
					<NcCheckboxRadioSwitch
						:checked.sync="menuItem.hideAfterInlog"
						:disabled="objectStore.isLoading('menu')">
						Hide after login
					</NcCheckboxRadioSwitch>
					<p class="help-text">
						When checked, this menu item will be hidden after a user is logged in.
						This is useful for menu items that should only be visible to guests.
					</p>
				</div>
			</div>

			<NcButton v-if="objectStore.getState('menu').success === null"
				v-tooltip="inputValidation.flatErrorMessages[0]"
				:disabled="objectStore.isLoading('menu') || !inputValidation.success"
				type="primary"
				class="singleModalAction"
				@click="saveMenuItem">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.isLoading('menu')" :size="20" />
					<ContentSaveOutline v-if="!objectStore.isLoading('menu') && isEdit" :size="20" />
					<Plus v-if="!objectStore.isLoading('menu') && !isEdit" :size="20" />
				</template>
				{{ isEdit ? 'Save' : 'Add' }}
			</NcButton>
		</div>
	</NcModal>
</template>

<script>
import _ from 'lodash'
import { Menu } from '../../entities/menu/menu.ts'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'MenuItemForm',
	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		// Icons
		ContentSaveOutline,
		Plus,
		Refresh,
	},
	data() {
		return {
			isEdit: !!objectStore.getActiveObject('menuItem'),
			index: objectStore.getActiveObject('menuItem')?.index ?? objectStore.getActiveObject('menu').items.length,
			menuItem: {
				order: 0,
				name: '',
				slug: '',
				link: '',
				description: '',
				icon: '',
				groups: [],
				hideAfterInlog: false,
				items: [],
			},
			iconOptions: {
				options: [
					{ label: 'arrow right', value: 'ARROW_RIGHT' },
					{ label: 'chevron right', value: 'CHEVRON_RIGHT' },
					{ label: 'chevron left', value: 'CHEVRON_LEFT' },
					{ label: 'close', value: 'CLOSE' },
					{ label: 'close small', value: 'CLOSE_SMALL' },
					{ label: 'contact', value: 'CONTACT' },
					{ label: 'document', value: 'DOCUMENT' },
					{ label: 'ellipse', value: 'ELLIPSE' },
					{ label: 'external link', value: 'EXTERNAL_LINK' },
					{ label: 'external link blue', value: 'EXTERNAL_LINK_BLUE' },
					{ label: 'external link pink', value: 'EXTERNAL_LINK_PINK' },
					{ label: 'filter', value: 'FILTER' },
					{ label: 'info', value: 'INFO' },
					{ label: 'info blue', value: 'INFO_BLUE' },
					{ label: 'list', value: 'LIST' },
					{ label: 'list blue', value: 'LIST_BLUE' },
					{ label: 'logo', value: 'LOGO' },
					{ label: 'menu', value: 'MENU' },
					{ label: 'question mark', value: 'QUESTION_MARK' },
					{ label: 'question mark vng', value: 'QUESTION_MARK_VNG' },
					{ label: 'search', value: 'SEARCH' },
					{ label: 'github', value: 'GITHUB' },
					{ label: 'common ground', value: 'COMMON_GROUND' },
					{ label: 'key', value: 'KEY' },
					{ label: 'person add', value: 'PERSON_ADD' },
					{ label: 'world', value: 'WORLD' },
					{ label: 'user', value: 'USER' },
					{ label: 'users', value: 'USERS' },
					{ label: 'building', value: 'BUILDING' },
					{ label: 'truck', value: 'TRUCK' },
					{ label: 'cube', value: 'CUBE' },
					{ label: 'hand holding', value: 'HAND_HOLDING' },
					{ label: 'house', value: 'HOUSE' },
					{ label: 'phone', value: 'PHONE' },
				],
				value: '',
			},
			groupsOptions: {
				options: [],
				value: [],
				loading: false,
			},
			closeModalTimeout: null,
		}
	},
	computed: {
		menuObject() {
			return objectStore.getActiveObject('menu')
		},
		inputValidation() {
			const updatedMenuItem = {
				...this.menuItem,
				icon: this.iconOptions.value?.value || '',
				groups: this.normalizeGroups(this.groupsOptions.value),
				order: Number(this.menuItem.order) || 0,
			}

			// Determine the new items array based on whether we're editing or adding
			const updatedItems = this.isEdit
				? this.menuObject.items.map(item =>
					item.id === objectStore.getActiveObject('menuItem').id
						? updatedMenuItem
						: item,
				)
				: [...this.menuObject.items, updatedMenuItem]

			// Create a temporary menu object for validation
			const tempMenu = {
				...this.menuObject,
				items: updatedItems,
			}

			const menuEntity = new Menu(tempMenu)
			const result = menuEntity.validate()
			return createZodErrorHandler(result)
		},
	},
	mounted() {
		this.fetchGroups()

		if (this.isEdit) {
			const menuItem = objectStore.getActiveObject('menuItem')
			this.menuItem = { ...menuItem }

			// Set the icon dropdown value
			if (menuItem.icon) {
				this.iconOptions.value = this.iconOptions.options.find(option => option.value === menuItem.icon)
			}

			// Set the groups dropdown value
			if (menuItem.groups && menuItem.groups.length > 0) {
				this.groupsOptions.value = menuItem.groups
			} else {
				this.groupsOptions.value = []
			}
		}
	},
	methods: {
		/**
		 * Fetch Nextcloud groups from the API
		 * @return {Promise<void>}
		 */
		async fetchGroups() {
			this.groupsOptions.loading = true
			try {
				const groups = await getNextcloudGroups()
				this.groupsOptions.options = groups

				// If we're editing and have groups, update the selected values
				if (this.isEdit && this.menuItem.groups && this.menuItem.groups.length > 0) {
					this.groupsOptions.value = this.menuItem.groups
				}
			} catch (error) {
				// Show user-friendly error message
				objectStore.setState('menu', {
					error: 'Could not load Nextcloud groups. Using fallback groups instead.',
				})

				// Clear error after 5 seconds
				setTimeout(() => {
					objectStore.setState('menu', { error: null })
				}, 5000)
			} finally {
				this.groupsOptions.loading = false
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('menuItem')
			objectStore.setState('menu', { success: null, error: null })
			clearTimeout(this.closeModalTimeout)
		},
		async saveMenuItem() {
			objectStore.setState('menu', { success: null, error: null, loading: true })

			const menuClone = _.cloneDeep(this.menuObject)

			const updatedMenuItem = {
				...this.menuItem,
				icon: this.iconOptions.value?.value || '',
				groups: this.normalizeGroups(this.groupsOptions.value),
				order: Number(this.menuItem.order) || 0,
			}

			if (this.isEdit) {
				// Find the item we're editing by ID
				const itemIndex = menuClone.items.findIndex(item => item.id === objectStore.getActiveObject('menuItem').id)
				if (itemIndex !== -1) {
					// Replace the existing item while preserving its ID
					menuClone.items[itemIndex] = {
						...updatedMenuItem,
						id: objectStore.getActiveObject('menuItem').id,
					}
				}
			} else {
				// Add new item without ID - let the backend handle ID generation
				// Set the order to the next available order number
				const maxOrder = Math.max(0, ...menuClone.items.map(item => item.order || 0))
				updatedMenuItem.order = maxOrder + 1
				menuClone.items.push(updatedMenuItem)
			}

			const newMenu = new Menu(menuClone)

			objectStore.updateObject('menu', this.menuObject.id, newMenu)
				.then(() => {
					objectStore.setState('menu', { success: true })
					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
					EventBus.$emit('edit-menu-item-success')
				})
				.catch((error) => {
					objectStore.setState('menu', { error: error.message || 'An error occurred while saving the menu' })
				})
				.finally(() => {
					objectStore.setState('menu', { loading: false })
				})
		},
		handleOrderUpdate(value) {
			const numeric = parseInt(value, 10)
			this.menuItem.order = Number.isNaN(numeric) ? 0 : numeric
		},
		normalizeGroups(selected) {
			if (!Array.isArray(selected)) return []
			return selected.map(item => {
				if (typeof item === 'string') return item
				if (item && typeof item === 'object') return item.value ?? String(item.label ?? '')
				return ''
			}).filter(Boolean)
		},
	},
}
</script>

<style scoped>

.form-container > * {
	margin-top: var(--OC-margin-20);
}

.form-actions {
	margin-top: var(--OC-margin-30);
	display: flex;
	justify-content: flex-end;
}

.groups-section {
	margin-top: var(--OC-margin-20);
}

.groups-label {
	display: block;
	margin-bottom: var(--OC-margin-10);
	font-weight: bold;
	color: var(--color-text);
}

.groups-select {
	width: 100%;
	min-height: 100px;
	padding: var(--OC-margin-10);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background);
	color: var(--color-text);
	font-size: 14px;
}

.groups-select:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.groups-loading {
	margin-top: var(--OC-margin-10);
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.groups-refresh {
	margin-top: var(--OC-margin-20);
}

.groups-refresh .nc-button {
	width: 100%;
	justify-content: center;
}

.hide-after-login {
	margin-top: var(--OC-margin-20);
	padding: var(--OC-margin-15);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.help-text {
	margin-top: var(--OC-margin-10);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
