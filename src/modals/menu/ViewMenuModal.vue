/**
 * ViewMenuModal.vue
 * Modal component for viewing menu details and menu items
 * @category Modals
 * @package opencatalogi
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/opencatalogi
 */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.modal === 'viewMenu'"
		:name="isAddMode ? 'Add Menu' : getModalTitle()"
		size="large"
		:can-close="true"
		@update:open="handleDialogClose">
		<div class="modal__content">
			<div v-if="menu || isAddMode" class="menuDetails">
				<!-- Menu Items Tab -->
				<div class="tabContainer">
					<BTabs v-model="tabIndex" content-class="mt-3" justified>
						<BTab v-if="!isAddMode" :title="`Menu Items (${editForm.items?.length || 0})`" active>
							<div v-if="editForm.items && editForm.items.length">
								<div class="menuItemsSection">
									<div v-if="editForm.items?.length" class="attached-list">
										<div v-for="(item, index) in editForm.items"
											:key="item.id || index"
											class="attached-list-item">
											<div class="object-info">
												<strong>{{ item.title || item.name || `Item ${index + 1}` }}</strong>
												<span v-if="item.order !== undefined" class="object-order">Order: {{ item.order }}</span>
												<span v-if="item.groups && item.groups.length > 0" class="object-groups">Groups: {{ item.groups.join(', ') }}</span>
												<span v-if="item.hideAfterInlog !== undefined" class="object-hide-after-login">Hide After Login: {{ item.hideAfterInlog ? 'Yes' : 'No' }}</span>
											</div>
											<div class="object-actions">
												<NcButton type="secondary" @click="editItem(item, index)">
													<template #icon>
														<Pencil :size="18" />
													</template>
													{{ t('opencatalogi', 'Edit') }},
												</NcButton>
												<NcButton type="error" @click="deleteItem(item)">
													<template #icon>
														<Delete :size="18" />
													</template>
													{{ t('opencatalogi', 'Delete') }}
												</NcButton>
											</div>
										</div>
									</div>
									<div v-else class="emptyMenuItems">
										<p>{{ t('opencatalogi', 'No menu items configured') }}</p>
									</div>
								</div>
							</div>

							<div v-else>
								<p class="emptyMenuItems">
									{{ t('opencatalogi', 'No menu items configured') }}
								</p>
							</div>
						</BTab>

						<!-- Configuration Tab -->
						<BTab title="Configuration">
							<div>
								<!-- Success/Error Messages -->
								<div v-if="menuState.success !== null || menuState.error" class="messageContainer">
									<NcNoteCard v-if="menuState.success" type="success">
										<p>{{ isEdit ? 'Menu successfully edited' : 'Menu successfully added' }}</p>
									</NcNoteCard>
									<NcNoteCard v-if="!menuState.success" type="error">
										<p>{{ isEdit ? 'Something went wrong while editing the menu' : 'Something went wrong while adding the menu' }}</p>
									</NcNoteCard>
									<NcNoteCard v-if="menuState.error" type="error">
										<p>{{ menuState.error }}</p>
									</NcNoteCard>
								</div>

								<!-- Edit Form -->
								<div v-if="menuState.success === null && !objectStore.isLoading('menu')" class="form-group">
									<NcTextField :disabled="objectStore.isLoading('menu')"
										label="Title*"
										maxlength="255"
										:value.sync="editForm.title"
										:error="!!inputValidation.fieldErrors?.['title']"
										:helper-text="inputValidation.fieldErrors?.['title']?.[0]" />

									<NcTextField :disabled="objectStore.isLoading('menu')"
										label="Description"
										maxlength="255"
										:value.sync="editForm.description"
										:error="!!inputValidation.fieldErrors?.['description']"
										:helper-text="inputValidation.fieldErrors?.['description']?.[0]" />

									<NcTextField :disabled="objectStore.isLoading('menu')"
										label="Icon"
										maxlength="255"
										:value.sync="editForm.icon"
										:error="!!inputValidation.fieldErrors?.['icon']"
										:helper-text="inputValidation.fieldErrors?.['icon']?.[0]" />

									<NcTextField :disabled="objectStore.isLoading('menu')"
										label="Position"
										type="number"
										min="0"
										:value="editForm.position"
										:error="!!inputValidation.fieldErrors?.['position']"
										:helper-text="inputValidation.fieldErrors?.['position']?.[0]"
										@update:value="handlePositionUpdate" />

									<div class="position-info">
										<p>1 - top right</p>
										<p>2 - navigation</p>
										<p>3 - footer left</p>
										<p>4 - footer center</p>
										<p>5 - footer right</p>
										<p>6 - footer bottom</p>
										<p>7 - admin</p>
									</div>
								</div>

								<div v-if="objectStore.isLoading('menu')" class="loading-status">
									<NcLoadingIcon :size="20" />
									<span>{{ isEdit ? 'Menu is being edited...' : 'Menu is being added...' }}</span>
								</div>
							</div>
						</BTab>

						<!-- Security Tab -->
						<BTab title="Security">
							<div>
								<!-- Groups Access Control -->
								<div class="groups-section">
									<label class="groups-label">Groups Access</label>
									<NcNoteCard type="info">
										<p>When you add groups to a menu, it will only appear if the user belongs to one of the selected groups. If no groups are selected, the menu will be visible to all users.</p>
									</NcNoteCard>
									<NcSelect
										v-model="editForm.groups"
										:options="groupsOptions?.options || []"
										:disabled="objectStore.isLoading('menu') || groupsOptions?.loading"
										input-label="Select Groups"
										multiple />
									<p v-if="groupsOptions?.loading" class="groups-loading">
										Loading groups...
									</p>
								</div>
								<div class="hide-after-login">
									<NcNoteCard type="info">
										<p>When checked, this menu will be hidden after a user is logged in. This is useful for menus that should only be visible to guests, such as login or registration links.</p>
									</NcNoteCard>
									<NcCheckboxRadioSwitch
										:checked.sync="editForm.hideAfterInlog"
										:disabled="editForm.showAfterLogin || objectStore.isLoading('menu')">
										Hide after login
									</NcCheckboxRadioSwitch>
									<NcCheckboxRadioSwitch
										:checked.sync="editForm.showAfterLogin"
										:disabled="editForm.hideAfterInlog || objectStore.isLoading('menu')">
										Show after login
									</NcCheckboxRadioSwitch>
									<p v-if="editForm.hideAfterInlog && editForm.showAfterLogin" class="field-error">
										'Show after login' and 'Hide after login' cannot both be selected.
									</p>
								</div>
							</div>
						</BTab>
					</BTabs>
				</div>

				<div v-if="menu && menu.metadata">
					<div class="metadataContainer">
						<pre>{{ JSON.stringify(menu.metadata, null, 2) }}</pre>
					</div>
				</div>
			</div>

			<div v-else class="emptyState">
				<p>{{ t('opencatalogi', 'No menu selected') }}</p>
			</div>
		</div>

		<template #actions>
			<NcButton v-if="!isAddMode" type="secondary" @click="openAddItemModal">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('opencatalogi', 'Add Item') }}
			</NcButton>
			<NcButton @click="closeModal">
				{{ t('opencatalogi', 'Close') }}
			</NcButton>
			<NcButton v-if="!isAddMode" type="error" @click="deleteMenu">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('opencatalogi', 'Delete') }}
			</NcButton>
			<NcButton
				v-if="isAddMode"
				type="primary"
				:disabled="!inputValidation.success || objectStore.isLoading('menu')"
				@click="saveMenu">
				<template #icon>
					<Plus :size="20" />
				</template>
				Create
			</NcButton>
			<NcButton
				v-else
				type="primary"
				:disabled="!inputValidation.success || objectStore.isLoading('menu')"
				@click="saveMenu">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.isLoading('menu')" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				Save
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField, NcNoteCard, NcLoadingIcon, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import { Menu } from '../../entities/index.js'
import _ from 'lodash'

export default {
	name: 'ViewMenuModal',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcNoteCard,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcSelect,
		BTabs,
		BTab,
		Pencil,
		Plus,
		Delete,
		ContentSave,
	},
	data() {
		return {
			editForm: {
				title: '',
				slug: '',
				link: '',
				description: '',
				icon: '',
				position: 0,
				items: [],
				groups: [],
				hideAfterInlog: false,
				showAfterLogin: false,
			},
			hasUpdated: false,
			groupsOptions: {
				options: [],
				loading: false,
			},
			tabIndex: 1, // 1 = Configuration by default for add, 0 = Menu Items
		}
	},
	computed: {
		/**
		 * Get the currently active menu from the store
		 * @return {object|null} The active menu object
		 */
		menu() {
			return objectStore.getActiveObject('menu')
		},
		/**
		 * Check if we're in edit mode
		 * @return {boolean} True if editing an existing menu
		 */
		isEdit() {
			return !!this.menu
		},
		/**
		 * Check if we're in add mode
		 * @return {boolean} True if adding a new menu
		 */
		isAddMode() {
			return !(this.menu && this.menu.id)
		},
		/**
		 * Get the menu state from the store
		 * @return {object} The menu state object
		 */
		menuState() {
			return objectStore.getState('menu')
		},
		/**
		 * Validate the input form
		 * @return {object} Validation result
		 */
		inputValidation() {
			const menuItem = new Menu(this.editForm)
			const result = menuItem.validate()

			return {
				success: result.success,
				errorMessages: result?.error?.issues.map((issue) => `${issue.path.join('.')}: ${issue.message}`) || [],
				fieldErrors: result?.error?.formErrors?.fieldErrors || {},
			}
		},
	},
	watch: {
		/**
		 * Watch for changes in the menu data and update editForm accordingly
		 * @param {object} newMenu - The new menu data
		 */
		menu: {
			handler(newMenu) {
				if (newMenu && !this.isAddMode) {
					this.editForm = {
						...this.editForm,
						..._.cloneDeep(newMenu),
					}
				} else if (this.isAddMode) {
					// Reset form for add mode
					this.editForm = {
						title: '', slug: '', link: '', description: '', icon: '', position: 0, items: [], groups: [], hideAfterInlog: false, showAfterLogin: false,
					}
				}
			},
			immediate: true,
		},
	},
	mounted() {
		// Initialize form when component mounts
		// Fetch groups for the dropdown
		this.fetchGroups && this.fetchGroups()
	},
	methods: {
		/**
		 * Get the modal title
		 * @return {string} The modal title
		 */
		getModalTitle() {
			return this.menu?.title || 'Menu'
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
		/**
		 * Close the modal and clear the active object
		 * @return {void}
		 */
		closeModal() {
			navigationStore.setModal(false)
			objectStore.clearActiveObject('menu')
		},
		/**
		 * Open the edit modal for the current menu
		 * @return {void}
		 */
		openEditModal() {
			navigationStore.setModal('viewMenu')
		},
		/**
		 * Open the add menu item modal
		 * @return {void}
		 */
		openAddItemModal() {
			objectStore.setState('menu', { success: null, error: null })
			navigationStore.setModal('menuItemForm')
		},
		/**
		 * Open the edit modal for a specific menu item
		 * @param {object} item - The menu item to edit
		 * @param {number} index - The index of the menu item
		 */
		editItem(item, index) {
			objectStore.setActiveObject('menuItem', { ...item, index })
			navigationStore.setModal('menuItemForm')
		},
		/**
		 * Open the delete modal for a specific menu item
		 * @param {object} item - The menu item to delete
		 */
		deleteItem(item) {
			objectStore.setActiveObject('menuItem', item)
			navigationStore.setModal('deleteMenuItem')
		},
		/**
		 * Handle position update
		 * @param {number} value - The new position value
		 */
		handlePositionUpdate(value) {
			this.editForm.position = parseInt(value, 10) || 0
		},
		/**
		 * Save the menu
		 */
		saveMenu() {
			const menuItem = new Menu({
				...this.editForm,
				position: parseInt(this.editForm.position, 10) || 0,
			})

			if (this.isEdit) {
				objectStore.updateObject('menu', menuItem.id, menuItem)
					.then(() => {
						const self = this
						setTimeout(function() {
							self.closeModal()
						}, 2000)
					})
			} else {
				objectStore.createObject('menu', menuItem)
					.then(() => {
						this.tabIndex = 0 // Switch to Menu Items tab
						// Optionally show a success message
					})
			}
		},
		/**
		 * Delete the current menu
		 * @return {void}
		 */
		deleteMenu() {
			if (this.menu && this.menu.id) {
				objectStore.deleteObject('menu', this.menu.id)
					.then(() => {
						this.closeModal()
					})
					.catch((error) => {
						console.error('Error deleting menu:', error)
					})
			}
		},
		fetchGroups() {
			this.groupsOptions.loading = true
			import('../../services/nextcloudGroups.js').then(({ getNextcloudGroups }) => {
				getNextcloudGroups()
					.then((groups) => {
						this.groupsOptions.options = groups
					})
					.catch((err) => {
						console.error('Error fetching groups:', err)
					})
					.finally(() => {
						this.groupsOptions.loading = false
					})
			})
		},
	},
}
</script>

<style scoped>
.modal__content {
	text-align: left;
}

.menuDetails {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-20);
	margin-top: var(--OC-margin-20);
}

.detailSection {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: var(--OC-margin-20);
}

.detailSection h3 {
	margin: 0 0 var(--OC-margin-15) 0;
	color: var(--color-primary);
	font-weight: bold;
}

.detailGrid {
	display: grid;
	grid-template-columns: 1fr;
	gap: var(--OC-margin-10);
}

.detailItem {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-5);
}

.detailItem strong {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.detailItem span {
	color: var(--color-main-text);
}

.detailItem a {
	color: var(--color-primary);
	text-decoration: none;
}

.detailItem a:hover {
	text-decoration: underline;
}

.menuItemsList {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-15);
}

.menuItem {
	padding: var(--OC-margin-15);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-primary);
}

.menuItemHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: var(--OC-margin-5);
}

.menuItemHeader strong {
	color: var(--color-main-text);
	font-size: 1em;
}

.menuItemActions {
	display: flex;
	gap: var(--OC-margin-10);
}

.menuItemOrder {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	background-color: var(--color-background-dark);
	padding: var(--OC-margin-5);
	border-radius: var(--border-radius);
}

.menuItemDescription {
	margin-bottom: var(--OC-margin-5);
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.menuItemLink,
.menuItemIcon,
.menuItemType {
	margin-bottom: var(--OC-margin-5);
	font-size: 0.85em;
}

.menuItemLink strong,
.menuItemIcon strong,
.menuItemType strong {
	color: var(--color-text-maxcontrast);
}

.menuItemLink a {
	color: var(--color-primary);
	text-decoration: none;
}

.menuItemLink a:hover {
	text-decoration: underline;
}

.menuItemGroups {
	margin-bottom: var(--OC-margin-5);
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.menuItemGroups strong {
	color: var(--color-text-maxcontrast);
}

.menuItemHideAfterLogin {
	margin-bottom: var(--OC-margin-5);
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.menuItemHideAfterLogin strong {
	color: var(--color-text-maxcontrast);
}

.emptyMenuItems {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: var(--OC-margin-20);
}

.emptyConfiguration {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: var(--OC-margin-20);
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: var(--OC-margin-10);
}

.loading-status {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 0.5rem;
	margin: 1rem 0;
	color: var(--color-text-lighter);
}

.position-info {
	text-align: left;
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin-top: -0.5rem;
	margin-bottom: 0.5rem;
}

.field-error {
	color: var(--color-error);
	text-align: left;
	font-size: 0.85em;
}

.messageContainer {
	margin-bottom: var(--OC-margin-15);
}

.emptySecurity {
	text-align: center;
	color: var(--color-text-lighter);
	font-style: italic;
	padding: var(--OC-margin-20);
}

.security-section {
	margin-block-start: var(--OC-margin-20);
	margin-block-end: var(--OC-margin-20);
}

.security-label {
	display: block;
	margin-block-end: var(--OC-margin-10);
	font-weight: bold;
}

.tabContainer {
	margin-top: var(--OC-margin-20);
}

.metadataContainer {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: var(--OC-margin-15);
	overflow-x: auto;
}

.metadataContainer pre {
	margin: 0;
	font-family: 'Courier New', monospace;
	font-size: 0.85em;
	color: var(--color-main-text);
	white-space: pre-wrap;
	word-wrap: break-word;
}

.emptyState {
	text-align: center;
	padding: var(--OC-margin-50);
	color: var(--color-text-lighter);
}

.menuItemsSection {
	margin-bottom: var(--OC-margin-20);
}
.section-title {
	margin: 0 0 8px 0;
	color: var(--color-primary);
	font-weight: bold;
}
.attached-list {
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow: hidden;
}
.attached-list-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}
.attached-list-item:last-child {
	border-bottom: none;
}
.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}
.object-order, .object-groups, .object-hide-after-login {
	display: inline-block;
	margin-right: 12px;
	color: var(--color-text-lighter);
	font-size: 0.9em;
}
.object-actions {
	display: flex;
	gap: var(--OC-margin-10);
}

.groups-section {
	margin-block-start: var(--OC-margin-20);
	margin-block-end: var(--OC-margin-20);
}
.groups-label {
	display: block;
	margin-block-end: var(--OC-margin-10);
	font-weight: bold;
}
.groups-loading {
	text-align: center;
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin-top: 0.5rem;
}

.hide-after-login {
	margin-block-start: var(--OC-margin-20);
	margin-block-end: var(--OC-margin-20);
}

@media (min-width: 768px) {
	.detailGrid {
		grid-template-columns: repeat(2, 1fr);
	}
}
</style>
