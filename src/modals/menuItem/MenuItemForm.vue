<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { createZodErrorHandler } from '../../services/formatZodErrors.js'
import { EventBus } from '../../eventBus.js'
import { getNextcloudGroups } from '../../services/nextcloudGroups.js'
</script>

<template>
	<NcDialog
		:name="isEdit ? 'Edit Menu Item' : 'Add Menu Item'"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<div class="dialog__content">
			<div v-if="objectStore.getState('menu').success !== null || objectStore.getState('menu').error">
				<NcNoteCard v-if="objectStore.getState('menu').success" type="success">
					<p>Menu item successfully {{ isEdit ? 'edited' : 'added' }}</p>
				</NcNoteCard>
				<NcNoteCard v-if="objectStore.getState('menu').error" type="error">
					<p>{{ objectStore.getState('menu').error }}</p>
				</NcNoteCard>
			</div>

			<div v-if="objectStore.getState('menu').success === null" class="tabContainer">
				<BTabs content-class="mt-3" justified>
					<!-- Configuration Tab -->
					<BTab title="Configuration" active>
						<div class="form-container">
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
								label="Name*"
								:value.sync="menuItem.name"
								:error="!!inputValidation.getError(`items.${index}.name`)"
								:helper-text="inputValidation.getError(`items.${index}.name`) || 'Name is required.'" />

							<NcTextField
								:disabled="objectStore.isLoading('menu')"
								label="Description"
								:value.sync="menuItem.description"
								:error="!!inputValidation.getError(`items.${index}.description`)"
								:helper-text="inputValidation.getError(`items.${index}.description`)" />

							<NcTextField
								:disabled="objectStore.isLoading('menu')"
								label="Link*"
								:helper-text="inputValidation.getError(`items.${index}.link`) || 'This can be an external link (e.g. https://www.opencatalogi.nl) or an internal path (e.g. /login). Link is required.'"
								:value.sync="menuItem.link"
								:error="!!inputValidation.getError(`items.${index}.link`)" />

							<!-- Debug Info -->
							<p>Debug: Current value = {{ iconOptions.value ? iconOptions.value.label : 'None' }}</p>
							<p>Debug: Options count = {{ iconOptions.options.length }}</p>

							<!-- Icon Preview -->
							<div v-if="iconOptions.value" class="selected-icon-preview">
								<p>Selected: <FontAwesomeIcon :icon="['fas', iconOptions.value.value]" /> {{ iconOptions.value.label }}</p>
							</div>

							<NcSelect 
								:value="iconOptions.value"
								:options="iconOptions.options"
								label="label"
								input-label="Icon"
								:disabled="objectStore.isLoading('menu')"
								@option:selected="handleIconSelect" />
						</div>
					</BTab>

					<!-- Security Tab -->
					<BTab title="Security">
						<div class="form-container">
							<div class="groups-section">
								<label class="groups-label">Groups Access</label>
								<NcNoteCard type="info">
									<p>When you add groups to a menu item, the item will only appear if the user belongs to one of the selected groups. If no groups are selected, the item will be visible to all users.</p>
								</NcNoteCard>
								<NcSelect
									v-model="groupsOptions.value"
									:options="groupsOptions.options"
									:disabled="objectStore.isLoading('menu') || groupsOptions.loading"
									input-label="Select Groups"
									multiple />
								<p v-if="groupsOptions.loading" class="groups-loading">
									Loading groups...
								</p>
							</div>

							<div class="hide-after-login">
								<NcNoteCard type="info">
									<p>When checked, this menu item will be hidden after a user is logged in. This is useful for menu items that should only be visible to guests, such as login or registration items.</p>
								</NcNoteCard>
								<NcCheckboxRadioSwitch
									:checked.sync="menuItem.hideAfterLogin"
									:disabled="menuItem.hideBeforeLogin || objectStore.isLoading('menu')">
									Verberg na inloggen
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									:checked.sync="menuItem.hideBeforeLogin"
									:disabled="menuItem.hideAfterLogin || objectStore.isLoading('menu')">
									Verberg voor inloggen
								</NcCheckboxRadioSwitch>
								<p v-if="menuItem.hideAfterLogin && menuItem.hideBeforeLogin" class="field-error">
									'Hide before login' and 'Hide after login' cannot both be selected.
								</p>
							</div>
						</div>
					</BTab>
				</BTabs>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				{{ isEdit ? 'Close' : 'Cancel' }}
			</NcButton>
			<NcButton v-if="objectStore.getState('menu').success === null"
				v-tooltip="inputValidation.flatErrorMessages[0]"
				:disabled="objectStore.isLoading('menu') || !inputValidation.success"
				type="primary"
				@click="saveMenuItem">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.isLoading('menu')" :size="20" />
					<ContentSaveOutline v-if="!objectStore.isLoading('menu') && isEdit" :size="20" />
					<Plus v-if="!objectStore.isLoading('menu') && !isEdit" :size="20" />
				</template>
				{{ isEdit ? 'Save' : 'Add' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import _ from 'lodash'
import { Menu } from '../../entities/menu/menu.ts'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextField, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'


export default {
	name: 'MenuItemForm',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		BTabs,
		BTab,
		// Icons
		ContentSaveOutline,
		Plus,
	},
	data() {
		return {
			isEdit: !!objectStore.getActiveObject('menuItem'),
			index: objectStore.getActiveObject('menuItem')?.index ?? objectStore.getActiveObject('menu').items.length,
			menuItem: {
				order: 0,
				name: '',
				link: '',
				description: '',
				icon: '',
				groups: [],
				hideAfterLogin: false,
				hideBeforeLogin: false,
				items: [],
			},
			iconOptions: {
				options: [
					{ label: '🏠 Home', value: 'house' },
					{ label: '👤 User', value: 'user' },
					{ label: '👥 Users', value: 'users' },
					{ label: '⚙️ Settings', value: 'gear' },
					{ label: '🔍 Search', value: 'magnifying-glass' },
					{ label: '📊 Dashboard', value: 'chart-line' },
					{ label: 'ℹ️ Info', value: 'info' },
					{ label: '❓ Question', value: 'question' },
					{ label: '❓ Help', value: 'circle-question' },
					{ label: '📞 Phone', value: 'phone' },
					{ label: '📧 Email', value: 'envelope' },
					{ label: '📇 Contact', value: 'address-book' },
					{ label: '🏢 Building', value: 'building' },
					{ label: '🌍 Globe', value: 'globe' },
					{ label: '🗺️ Map', value: 'map' },
					{ label: '📍 Location', value: 'location-dot' },
					{ label: '🔑 Key', value: 'key' },
					{ label: '🔒 Lock', value: 'lock' },
					{ label: '🔓 Unlock', value: 'unlock' },
					{ label: '🛡️ Shield', value: 'shield' },
					{ label: '📄 Document', value: 'file' },
					{ label: '📝 File Text', value: 'file-lines' },
					{ label: '📁 Folder', value: 'folder' },
					{ label: '📖 Book', value: 'book' },
					{ label: '🔖 Bookmark', value: 'bookmark' },
					{ label: '🏷️ Tag', value: 'tag' },
					{ label: '🏷️ Tags', value: 'tags' },
					{ label: '⭐ Star', value: 'star' },
					{ label: '❤️ Heart', value: 'heart' },
					{ label: '➕ Plus', value: 'plus' },
					{ label: '➖ Minus', value: 'minus' },
					{ label: '✅ Check', value: 'check' },
					{ label: '❌ Times', value: 'xmark' },
					{ label: '➡️ Arrow Right', value: 'arrow-right' },
					{ label: '⬅️ Arrow Left', value: 'arrow-left' },
					{ label: '⬆️ Arrow Up', value: 'arrow-up' },
					{ label: '⬇️ Arrow Down', value: 'arrow-down' },
					{ label: '▶️ Chevron Right', value: 'chevron-right' },
					{ label: '◀️ Chevron Left', value: 'chevron-left' },
					{ label: '🔼 Chevron Up', value: 'chevron-up' },
					{ label: '🔽 Chevron Down', value: 'chevron-down' },
					{ label: '☰ Menu', value: 'bars' },
					{ label: '⚏ Grid', value: 'table-cells' },
					{ label: '📋 List', value: 'list' },
					{ label: '📅 Calendar', value: 'calendar' },
					{ label: '🕐 Clock', value: 'clock' },
					{ label: '🛒 Shopping Cart', value: 'shopping-cart' },
					{ label: '💳 Credit Card', value: 'credit-card' },
					{ label: '💲 Money', value: 'dollar-sign' },
					{ label: '🔔 Bell', value: 'bell' },
					{ label: '🚩 Flag', value: 'flag' },
					{ label: '📷 Camera', value: 'camera' },
					{ label: '🖼️ Image', value: 'image' },
					{ label: '🎥 Video', value: 'video' },
					{ label: '🎵 Music', value: 'music' },
					{ label: '🎧 Headphones', value: 'headphones' },
					{ label: '🎤 Microphone', value: 'microphone' },
					{ label: '🔊 Volume Up', value: 'volume-up' },
					{ label: '🔉 Volume Down', value: 'volume-down' },
					{ label: '🔇 Volume Mute', value: 'volume-xmark' },
					{ label: '📶 WiFi', value: 'wifi' },
					{ label: '📶 Signal', value: 'signal' },
					{ label: '🔋 Battery', value: 'battery-three-quarters' },
					{ label: '⚡ Power', value: 'power-off' },
					{ label: '🖨️ Printer', value: 'print' },
					{ label: '⬇️ Download', value: 'download' },
					{ label: '⬆️ Upload', value: 'upload' },
					{ label: '🔗 Share', value: 'share' },
					{ label: '🔗 External Link', value: 'external-link' },
					{ label: '🔗 Link', value: 'link' },
					{ label: '💥 Chain Broken', value: 'link-slash' },
					{ label: '📋 Copy', value: 'copy' },
					{ label: '📋 Paste', value: 'paste' },
					{ label: '✂️ Cut', value: 'scissors' },
					{ label: '💾 Save', value: 'floppy-disk' },
					{ label: '✏️ Edit', value: 'pen' },
					{ label: '🗑️ Trash', value: 'trash' },
					{ label: '🔄 Refresh', value: 'arrows-rotate' },
					{ label: '🔄 Sync', value: 'rotate' },
					{ label: '🔍 Filter', value: 'filter' },
					{ label: '🔤 Sort', value: 'sort' },
					{ label: '🔼 Sort Up', value: 'sort-up' },
					{ label: '🔽 Sort Down', value: 'sort-down' },
					{ label: '🔍 Expand', value: 'expand' },
					{ label: '🗜️ Compress', value: 'compress' },
					{ label: '👁️ Eye', value: 'eye' },
					{ label: '👁️‍🗨️ Eye Slash', value: 'eye-slash' },
					{ label: '🔛 Toggle On', value: 'toggle-on' },
					{ label: '🔘 Toggle Off', value: 'toggle-off' },
					{ label: '💡 Lightbulb', value: 'lightbulb' },
					{ label: '🔧 Tools', value: 'tools' },
					{ label: '🔧 Wrench', value: 'wrench' },
					{ label: '🔨 Hammer', value: 'hammer' },
					{ label: '⚙️ Cog', value: 'cog' },
					{ label: '🗄️ Database', value: 'database' },
					{ label: '🖥️ Server', value: 'server' },
					{ label: '☁️ Cloud', value: 'cloud' },
					{ label: '🚛 Truck', value: 'truck' },
					{ label: '🚗 Car', value: 'car' },
					{ label: '✈️ Plane', value: 'plane' },
					{ label: '🚢 Ship', value: 'ship' },
					{ label: '🚂 Train', value: 'train' },
					{ label: '🚲 Bicycle', value: 'bicycle' },
					{ label: '🚶 Walking', value: 'person-walking' },
					{ label: '🏃 Running', value: 'person-running' },
					{ label: '🤝 Handshake', value: 'handshake' },
					{ label: '👍 Thumbs Up', value: 'thumbs-up' },
					{ label: '👎 Thumbs Down', value: 'thumbs-down' },
					{ label: '🔥 Fire', value: 'fire' },
					{ label: '⚡ Bolt', value: 'bolt' },
					{ label: '☀️ Sun', value: 'sun' },
					{ label: '🌙 Moon', value: 'moon' },
					{ label: '❄️ Snowflake', value: 'snowflake' },
					{ label: '🍃 Leaf', value: 'leaf' },
					{ label: '🌳 Tree', value: 'tree' },
					{ label: '⛰️ Mountain', value: 'mountain' },
					{ label: '💧 Water', value: 'water' }
				],
				value: null,
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
				hideBeforeLogin: this.menuItem.hideBeforeLogin,
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
		objectStore.setState('menu', { success: null, error: null })
		this.fetchGroups()

		if (this.isEdit) {
			const menuItem = objectStore.getActiveObject('menuItem')
			this.menuItem = { ...menuItem }

			// Set the icon dropdown value
			if (menuItem.icon) {
				this.iconOptions.value = this.iconOptions.options.find(option => option.value === menuItem.icon) || null
			} else {
				this.iconOptions.value = null
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
		/**
		 * Handle dialog close event
		 * @param {boolean} isOpen - Whether the dialog is open
		 * @return {void}
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeModal()
			}
		},
		closeModal() {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('menuItem')
			objectStore.setState('menu', { success: null, error: null })
			clearTimeout(this.closeModalTimeout)
		},
		/**
		 * Save the menu item (either create new or update existing)
		 * @return {Promise<void>}
		 */
		async saveMenuItem() {
			objectStore.setState('menu', { success: null, error: null, loading: true })

			const menuClone = _.cloneDeep(this.menuObject)
			const activeMenuItem = objectStore.getActiveObject('menuItem')

			const updatedMenuItem = {
				...this.menuItem,
				icon: this.iconOptions.value?.value || '',
				groups: this.normalizeGroups(this.groupsOptions.value),
				order: Number(this.menuItem.order) || 0,
				hideBeforeLogin: this.menuItem.hideBeforeLogin,
			}

			if (this.isEdit && activeMenuItem) {
				let itemIndex = -1
				if (activeMenuItem.index !== undefined && activeMenuItem.index >= 0 && activeMenuItem.index < menuClone.items.length) {
					itemIndex = activeMenuItem.index
				} else {
					if (activeMenuItem.id && activeMenuItem.id !== null && activeMenuItem.id !== undefined) {
						itemIndex = menuClone.items.findIndex(item => item.id === activeMenuItem.id)
					}

					if (itemIndex === -1) {
						itemIndex = menuClone.items.findIndex(item =>
							item.name === activeMenuItem.name
							&& item.order === activeMenuItem.order,
						)
					}
				}

				if (itemIndex !== -1 && itemIndex < menuClone.items.length) {
					menuClone.items[itemIndex] = {
						...updatedMenuItem,
						id: activeMenuItem.id || menuClone.items[itemIndex].id,
					}
				} else {
					objectStore.setState('menu', { error: 'Could not find menu item to edit' })
					objectStore.setState('menu', { loading: false })
					return
				}
			} else {
				const maxOrder = Math.max(0, ...menuClone.items.map(item => item.order || 0))
				updatedMenuItem.order = maxOrder + 1
				menuClone.items.push(updatedMenuItem)
			}

			const newMenu = new Menu(menuClone)

			objectStore.updateObject('menu', this.menuObject.id, newMenu)
				.then(() => {
					objectStore.setState('menu', { success: true })
					// Wait for the user to read the feedback then return to parent dialog
					this.closeModalTimeout = setTimeout(() => {
						navigationStore.setModal('viewMenu')
					}, 2000)
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
		handleIconSelect(selectedOption) {
			console.log('Icon selected:', selectedOption)
			this.iconOptions.value = selectedOption
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

.tabContainer {
	margin-top: var(--OC-margin-20);
}

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

.groups-loading {
	margin-top: var(--OC-margin-10);
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.hide-after-login {
	margin-top: var(--OC-margin-20);
}

.field-error {
	margin-top: var(--OC-margin-10);
	font-size: 0.9em;
	color: var(--color-error);
	font-style: italic;
}

.help-text {
	margin-top: var(--OC-margin-10);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.icon-option {
	display: flex;
	align-items: center;
	gap: 8px;
}

.icon-preview {
	width: 16px;
	height: 16px;
	color: var(--color-text-light);
}
</style>
