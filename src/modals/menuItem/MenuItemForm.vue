<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
import { EventBus } from '../../eventBus.js'
</script>

<template>
	<CnFormDialog
		v-if="navigationStore.modal === 'menuItemForm'"
		:dialog-title="isEdit ? t('opencatalogi', 'Edit Menu Item') : t('opencatalogi', 'Add Menu Item')"
		:fields="fields"
		:item="dialogItem"
		size="large"
		@confirm="onConfirm"
		@close="closeModal" />
</template>

<script>
import _ from 'lodash'
import { CnFormDialog } from '@conduction/nextcloud-vue'
import { Menu } from '../../entities/menu/menu.ts'
import { getNextcloudGroups } from '../../services/nextcloudGroups.js'
import { buildMenuItemIconCatalogues } from './menuItemIconCatalogues.js'

/**
 * MenuItemForm — add/edit a menu item, rendered through the shared
 * schema-driven CnFormDialog. The `icon` field uses the `widget: 'icon'`
 * renderer (CnIconPicker) with three sources — Material (MDI), FontAwesome, and
 * a small OpenGemeenten sample — plus a custom-SVG editor. Persists the item
 * back into the parent menu's `items` array.
 *
 * @category Components
 * @package opencatalogi
 * @author Ruben Linde
 * @license AGPL-3.0-or-later
 */
export default {
	name: 'MenuItemForm',
	components: { CnFormDialog },
	data() {
		const activeMenuItem = objectStore.getActiveObject('menuItem')
		return {
			isEdit: !!activeMenuItem,
			fields: [
				{ key: 'order', label: t('opencatalogi', 'Order'), widget: 'number' },
				{ key: 'name', label: t('opencatalogi', 'Name'), widget: 'text', required: true },
				{ key: 'link', label: t('opencatalogi', 'Link'), widget: 'text', description: t('opencatalogi', 'External link (https://…) or internal path (/login).') },
				{ key: 'description', label: t('opencatalogi', 'Description'), widget: 'text' },
				{ key: 'ariaLabel', label: t('opencatalogi', 'Aria Label'), widget: 'text', description: t('opencatalogi', 'Accessible name announced by screen readers.') },
				{
					key: 'icon',
					label: t('opencatalogi', 'Icon'),
					widget: 'icon',
					iconSources: ['mdi', 'fontawesome', 'opengemeenten'],
					catalogues: buildMenuItemIconCatalogues(),
					searchable: true,
					allowCustomSvg: true,
				},
				{ key: 'groups', label: t('opencatalogi', 'Groups Access'), widget: 'multiselect', enum: async () => (await getNextcloudGroups()).map(g => ({ label: g.label, value: g.value, id: g.value })), description: t('opencatalogi', 'When set, the item only shows for users in one of these groups.') },
				{ key: 'hideAfterLogin', label: t('opencatalogi', 'Hide after login'), widget: 'checkbox' },
				{ key: 'hideBeforeLogin', label: t('opencatalogi', 'Hide before login'), widget: 'checkbox' },
			],
		}
	},
	computed: {
		/** @return {object|null} The active menu whose items are edited. */
		menuObject() {
			return objectStore.getActiveObject('menu')
		},
		/** @return {object|null} Flattened item for CnFormDialog (null in create mode). */
		dialogItem() {
			const raw = objectStore.getActiveObject('menuItem')
			if (!raw) {
				return null
			}
			return {
				order: Number(raw.order || 0),
				name: raw.name ?? '',
				link: raw.link ?? '',
				description: raw.description ?? '',
				ariaLabel: raw.ariaLabel ?? '',
				icon: raw.icon ?? '',
				groups: Array.isArray(raw.groups) ? raw.groups : [],
				hideAfterLogin: !!raw.hideAfterLogin,
				hideBeforeLogin: !!raw.hideBeforeLogin,
			}
		},
	},
	methods: {
		/**
		 * Persist the confirmed form data back into the parent menu.
		 *
		 * @param {object} formData The CnFormDialog payload, keyed by field.
		 * @return {void}
		 */
		onConfirm(formData) {
			const menuClone = _.cloneDeep(this.menuObject)
			if (!Array.isArray(menuClone.items)) {
				menuClone.items = []
			}
			const activeMenuItem = objectStore.getActiveObject('menuItem')
			const item = {
				order: Number(formData.order) || 0,
				name: formData.name || '',
				link: formData.link || '',
				description: formData.description || '',
				ariaLabel: formData.ariaLabel || '',
				icon: formData.icon || '',
				groups: this.normalizeGroups(formData.groups),
				hideAfterLogin: !!formData.hideAfterLogin,
				hideBeforeLogin: !!formData.hideBeforeLogin,
			}

			if (this.isEdit && activeMenuItem) {
				let index = -1
				if (activeMenuItem.index !== undefined && activeMenuItem.index >= 0 && activeMenuItem.index < menuClone.items.length) {
					index = activeMenuItem.index
				} else if (activeMenuItem.id) {
					index = menuClone.items.findIndex(i => i.id === activeMenuItem.id)
				}
				if (index === -1) {
					index = menuClone.items.findIndex(i => i.name === activeMenuItem.name && i.order === activeMenuItem.order)
				}
				if (index !== -1) {
					menuClone.items[index] = { ...item, id: activeMenuItem.id || menuClone.items[index].id }
				} else {
					menuClone.items.push(item)
				}
			} else {
				const maxOrder = Math.max(0, ...menuClone.items.map(i => i.order || 0))
				item.order = item.order || maxOrder + 1
				menuClone.items.push(item)
			}

			objectStore.updateObject('menu', this.menuObject.id, new Menu(menuClone))
				.then(() => {
					navigationStore.setModal('viewMenu')
					objectStore.clearActiveObject('menuItem')
					EventBus.$emit('edit-menu-item-success')
				})
				.catch((error) => {
					console.error('Error saving menu item:', error)
				})
		},
		/**
		 * Normalize the groups multiselect value to an array of group ids.
		 *
		 * @param {Array} selected The selected options.
		 * @return {Array<string>} Group ids.
		 */
		normalizeGroups(selected) {
			if (!Array.isArray(selected)) {
				return []
			}
			return selected.map(item => (typeof item === 'string' ? item : (item.value ?? item.id ?? item.label))).filter(Boolean)
		},
		/**
		 * Close the dialog and return to the parent menu view.
		 *
		 * @return {void}
		 */
		closeModal() {
			navigationStore.setModal('viewMenu')
			objectStore.clearActiveObject('menuItem')
		},
	},
}
</script>
