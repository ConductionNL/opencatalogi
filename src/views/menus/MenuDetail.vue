/**
 * MenuDetail.vue
 * Component for displaying and managing menu details
 * @category Views
 * @package opencatalogi
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/opencatalogi
 */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
import { getTheme } from '../../services/getTheme.js'
</script>

<template>
	<div class="detailContainer">
		<div class="head">
			<h1 class="h1">
				{{ menu.title }}
			</h1>

			<NcActions
				:disabled="objectStore.isLoading('menu')"
				:primary="true"
				:menu-name="objectStore.isLoading('menu') ? 'Loading...' : 'Actions'"
				:inline="1"
				title="Actions you can perform on this page">
				<template #icon>
					<span>
						<NcLoadingIcon v-if="objectStore.isLoading('menu')"
							:size="20"
							appearance="dark" />
						<DotsHorizontal v-if="!objectStore.isLoading('menu')" :size="20" />
					</span>
				</template>
				<NcActionButton
					title="View the documentation about pages"
					@click="openLink('https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/paginas')">
					<template #icon>
						<HelpCircleOutline :size="20" />
					</template>
					Help
				</NcActionButton>
				<NcActionButton close-after-click @click="onActionButtonClick(menu, 'edit')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					Edit
				</NcActionButton>
				<NcActionButton close-after-click @click="onActionButtonClick(menu, 'addContent')">
					<template #icon>
						<Plus :size="20" />
					</template>
					Add menu item
				</NcActionButton>
				<NcActionButton close-after-click @click="onActionButtonClick(menu, 'copyObject')">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
					Copy
				</NcActionButton>
				<NcActionButton close-after-click @click="onActionButtonClick(menu, 'deleteObject')">
					<template #icon>
						<Delete :size="20" />
					</template>
					Delete
				</NcActionButton>
			</NcActions>
		</div>
		<div class="container">
			<div class="detailGrid">
				<div>
					<b>Title:</b>
					<span>{{ menu.title }}</span>
				</div>
				<div>
					<b>Slug:</b>
					<span>{{ menu.slug }}</span>
				</div>
				<div>
					<b>Link:</b>
					<span>{{ menu.link }}</span>
				</div>
				<div>
					<b>Description:</b>
					<span>{{ menu.description }}</span>
				</div>
				<div>
					<b>Icon:</b>
					<span>{{ menu.icon }}</span>
				</div>
				<div>
					<b>Position:</b>
					<span v-if="menu.position === 0">{{ menu.position }} - right above</span>
					<span v-else-if="menu.position === 1">{{ menu.position }} - navigation bar</span>
					<span v-else-if="menu.position === 2">{{ menu.position }} - footer</span>
					<span v-else>{{ menu.position }} - not defined</span>
				</div>
				<div>
					<b>Last updated:</b>
					<span>{{ menu.updatedAt }}</span>
				</div>
			</div>
		</div>

		<div class="tabContainer">
			<BTabs content-class="mt-3" justified>
				<BTab active>
					<template #title>
						<div class="tabTitleLoadingContainer">
							<p>Menu items:</p>
							<NcLoadingIcon v-if="safeItemsLoading" class="tabTitleIcon" :size="24" />
							<CheckCircleOutline v-if="safeItemsLoadingSuccess" class="tabTitleIcon" :size="24" />
						</div>
					</template>

					<!-- if menu has items -->
					<div v-if="menuItems.length > 0">
						<!-- show draggable list -->
						<VueDraggable v-model="menuItems" easing="ease-in-out">
							<!-- show a div which is draggable for each item -->
							<div v-for="(menuItem, i) in menuItems" :key="i" :class="`draggable-list-item ${getTheme()}`">
								<!-- show a drag handle and NcListItem -->
								<Drag class="drag-handle" :size="40" />
								<NcListItem :name="menuItem.name"
									:bold="false"
									:force-display-actions="true">
									<template #subname>
										{{ menuItem.description }}
										<span v-if="menuItem.groups && menuItem.groups.length > 0" class="menu-item-groups">
											<br><small>Groups: {{ menuItem.groups.join(', ') }}</small>
										</span>
										<span v-if="menuItem.hideAfterInlog" class="menu-item-hide-after-login">
											<br><small>🔒 Verborgen na inloggen</small>
										</span>
									</template>
									<template #actions>
										<NcActionButton close-after-click
											:disabled="safeItemsLoading"
											@click="onMenuItemActionButtonClick(menuItem, 'edit')">
											<template #icon>
												<Pencil :size="20" />
											</template>
											Edit menu item
										</NcActionButton>
										<NcActionButton close-after-click
											:disabled="safeItemsLoading"
											@click="onMenuItemActionButtonClick(menuItem, 'delete')">
											<template #icon>
												<Delete :size="20" />
											</template>
											Delete menu item
										</NcActionButton>
									</template>
								</NcListItem>
							</div>
						</VueDraggable>

						<NcButton :disabled="(JSON.stringify(menu.items) === JSON.stringify(menuItems)) || safeItemsLoading"
							@click="saveMenuItems">
							Save
						</NcButton>
					</div>
					<div v-else>
						No menu items found
					</div>
				</BTab>
			</BTabs>
		</div>
	</div>
</template>

<script>
// Components
import { NcActionButton, NcActions, NcLoadingIcon, NcListItem, NcButton } from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'
import { BTabs, BTab } from 'bootstrap-vue'

// Icons
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Drag from 'vue-material-design-icons/Drag.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import _ from 'lodash'
import { Menu } from '../../entities/index.js'

export default {
	name: 'MenuDetail',
	components: {
		// Components
		NcLoadingIcon,
		NcActionButton,
		NcActions,
		NcListItem,
		NcButton,
		// Bootstrap
		BTabs,
		BTab,
		// Icons
		DotsHorizontal,
		Pencil,
		Delete,
		ContentCopy,
		HelpCircleOutline,
		Plus,
		Drag,
		CheckCircleOutline,
	},
	data() {
		return {
			menuItems: [],
			safeItemsLoading: false,
			safeItemsLoadingSuccess: false,
		}
	},
	computed: {
		menu() {
			return objectStore.getActiveObject('menu')
		},
	},
	watch: {
		menu: {
			handler(newMenu) {
				if (newMenu) {
					this.menuItems = newMenu.items.map((item, index) => ({
						...item,
						id: index,
					}))
				}
			},
			immediate: true,
		},
	},
	methods: {
		resetItemsIndexes() {
			this.menuItems = this.menuItems.map((item, index) => ({
				...item,
				id: index,
			}))
		},
		openLink(url, type = '') {
			window.open(url, type)
		},
		saveMenuItems() {
			this.safeItemsLoading = true
			this.safeItemsLoadingSuccess = false

			const menuClone = _.cloneDeep(this.menu)
			menuClone.items = this.menuItems

			const newMenu = new Menu(menuClone)

			objectStore.updateObject('menu', newMenu.id, newMenu)
				.then(() => {
					this.resetItemsIndexes()
					this.safeItemsLoadingSuccess = true
					setTimeout(() => {
						this.safeItemsLoadingSuccess = false
					}, 1500)
				})
				.finally(() => {
					this.safeItemsLoading = false
				})
		},

		/**
		 * Delete a menu item
		 * @param {string} menuItemId - The ID of the menu item to delete
		 * @return {Promise<void>}
		 */
		async deleteMenuItem(menuItemId) {
			if (!this.menu) return

			const newMenuItems = this.menu.items.filter((item) => item.id !== menuItemId)
			const newMenu = new Menu({
				...this.menu,
				items: newMenuItems,
			})
			await objectStore.updateObject('menu', newMenu.id, newMenu).then(() => {
				this.menuItems = newMenuItems
			}).catch((err) => {
				objectStore.setState('menu', { error: err })
			}).finally(() => {
				objectStore.setState('menu', { success: null, error: null, loading: false })
			})
			objectStore.clearActiveObject('menuItem')
		},

		onActionButtonClick(menu, action) {
			objectStore.setActiveObject('menu', menu)
			switch (action) {
			case 'edit':
				navigationStore.setModal('menu')
				break
			case 'addContent':
				navigationStore.setModal('menuItemForm')
				break
			case 'copyObject':
			case 'deleteObject':
				navigationStore.setDialog(action, { objectType: 'menu', dialogTitle: 'Menu' })
				break
			}
		},

		onMenuItemActionButtonClick(menuItem, action) {
			objectStore.setActiveObject('menuItem', menuItem)
			switch (action) {
			case 'edit':
				navigationStore.setModal('menuItemForm')
				break
			case 'delete':
				this.deleteMenuItem(menuItem.id)
				break
			}
		},
	},
}
</script>

<style>
h4 {
  font-weight: bold;
}

.head{
	display: flex;
	justify-content: space-between;
}

.button{
	max-height: 10px;
}

.h1 {
  display: block !important;
  font-size: 2em !important;
  margin-block-start: 0.67em !important;
  margin-block-end: 0.67em !important;
  margin-inline-start: 0px !important;
  margin-inline-end: 0px !important;
  font-weight: bold !important;
  unicode-bidi: isolate !important;
}

.dataContent {
  display: flex;
  flex-direction: column;
}

.active.menuDetails-actionsDelete {
    background-color: var(--color-error) !important;
}
.active.menuDetails-actionsDelete button {
    color: #EBEBEB !important;
}

.MenuDetail-clickable {
    cursor: pointer !important;
}

.buttonLinkContainer{
	display: flex;
    align-items: center;
}

.float-right {
    float: right;
}
</style>

<style scoped>
.tabTitleLoadingContainer {
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
}
.tabTitleLoadingContainer .tabTitleIcon {
    position: absolute;
    right: 0;
}

/* draggable list item */
.draggable-list-item {
    display: flex;
    align-items: center;
    gap: 3px;

    background-color: rgba(255, 255, 255, 0.05);
    padding: 4px;
    border-radius: 8px;

    margin-block: 8px;
}
.draggable-list-item.light {
    background-color: rgba(0, 0, 0, 0.05);
}

.menu-item-groups {
    color: var(--color-text-maxcontrast);
    font-style: italic;
}

.menu-item-groups small {
    opacity: 0.8;
}

.menu-item-hide-after-login {
	color: var(--color-warning);
	font-style: italic;
}

.menu-item-hide-after-login small {
	opacity: 0.9;
}
</style>
