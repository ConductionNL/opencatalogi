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

							<div v-if="isFooterPosition" class="viewModeSwitchContainer">
								<NcCheckboxRadioSwitch
									v-tooltip="'Use a standard link'"
									:checked="linkMode === 'link'"
									:button-variant="true"
									:class="{ 'checkbox-radio-switch--checked': linkMode === 'link' }"
									value="link"
									name="link_type"
									type="radio"
									button-variant-grouped="horizontal"
									@update:checked="() => setLinkMode('link')">
									Link
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									v-tooltip="'Use a markdown link'"
									:checked="linkMode === 'markdown'"
									:button-variant="true"
									:class="{ 'checkbox-radio-switch--checked': linkMode === 'markdown' }"
									value="markdown"
									name="link_type"
									type="radio"
									button-variant-grouped="horizontal"
									@update:checked="() => setLinkMode('markdown')">
									Markdown Link
								</NcCheckboxRadioSwitch>
							</div>

							<NcTextField
								:disabled="objectStore.isLoading('menu')"
								:label="linkMode === 'markdown' ? 'Markdown Link*' : 'Link*'"
								:helper-text="inputValidation.getError(`items.${index}.link`) || (linkMode === 'markdown' ? 'Enter a markdown-formatted link (e.g. [Text](https://example.com)) or internal anchor.' : 'This can be an external link (e.g. https://www.opencatalogi.nl) or an internal path (e.g. /login). Link is required.')"
								:value.sync="menuItem.link"
								:error="!!inputValidation.getError(`items.${index}.link`)" />

							<div v-if="isFooterPosition" class="viewModeSwitchContainer">
								<NcCheckboxRadioSwitch
									v-tooltip="'Use a single-line value'"
									:checked="valueMode === 'value'"
									:button-variant="true"
									:class="{ 'checkbox-radio-switch--checked': valueMode === 'value' }"
									value="value"
									name="value_type"
									type="radio"
									button-variant-grouped="horizontal"
									@update:checked="() => setValueMode('value')">
									Value
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									v-tooltip="'Use a title (no link)'"
									:checked="valueMode === 'title'"
									:button-variant="true"
									:class="{ 'checkbox-radio-switch--checked': valueMode === 'title' }"
									value="title"
									name="value_type"
									type="radio"
									button-variant-grouped="horizontal"
									@update:checked="() => setValueMode('title')">
									Title
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									v-tooltip="'Use a multi-row text'"
									:checked="valueMode === 'multiRow'"
									:button-variant="true"
									:class="{ 'checkbox-radio-switch--checked': valueMode === 'multiRow' }"
									value="multiRow"
									name="value_type"
									type="radio"
									button-variant-grouped="horizontal"
									@update:checked="() => setValueMode('multiRow')">
									MultiRow
								</NcCheckboxRadioSwitch>
							</div>

							<NcTextArea
								v-if="isFooterPosition && valueMode === 'multiRow'"
								:disabled="objectStore.isLoading('menu')"
								label="Value"
								:value.sync="menuItem.value"
								:helper-text="inputValidation.getError(`items.${index}.value`) || 'This will be displayed as a multi-row text. The link will not be used. If no value is set, the name will be used.'" />

							<NcTextField
								v-if="isFooterPosition && valueMode !== 'multiRow'"
								:disabled="objectStore.isLoading('menu')"
								label="Value"
								:value.sync="menuItem.value"
								:helper-text="inputValidation.getError(`items.${index}.value`) || (valueMode === 'title' ? 'This will be displayed as a title. The link will not be used. If no value is set, the name will be used.' : 'If no value is set, the name will be used.')"
								@update:value="onSingleLineValueChange" />

							<NcTextField
								:disabled="objectStore.isLoading('menu')"
								label="Aria Label"
								:helper-text="inputValidation.getError(`items.${index}.ariaLabel`) || 'This label is used for the aria-label attribute, providing an accessible name for the menu item to assistive technologies like screen readers.'"
								:value.sync="menuItem.ariaLabel"
								:error="!!inputValidation.getError(`items.${index}.ariaLabel`)" />

							<div class="viewModeSwitchContainer">
								<NcCheckboxRadioSwitch
									v-tooltip="'Use a standard icon'"
									:checked="iconMode === 'standard'"
									:button-variant="true"
									:class="{ 'checkbox-radio-switch--checked': iconMode === 'standard' }"
									value="standard"
									name="icon_source"
									type="radio"
									button-variant-grouped="horizontal"
									@update:checked="() => setIconMode('standard')">
									Icon
								</NcCheckboxRadioSwitch>
								<NcCheckboxRadioSwitch
									v-model="iconMode"
									v-tooltip="'Use a custom icon'"
									:checked="iconMode === 'custom'"
									:button-variant="true"
									:class="{ 'checkbox-radio-switch--checked': iconMode === 'custom' }"
									value="custom"
									name="icon_source"
									type="radio"
									button-variant-grouped="horizontal"
									@update:checked="() => setIconMode('custom')">
									Custom
								</NcCheckboxRadioSwitch>
							</div>

							<NcSelect
								v-model="iconPlacementOptions.value"
								:options="iconPlacementOptions.options"
								label="label"
								input-label="Icon Placement"
								track-by="value"
								:disabled="objectStore.isLoading('menu')" />

							<NcSelect
								v-if="iconMode === 'standard'"
								v-model="iconOptions.value"
								:options="iconOptions.options"
								label="label"
								input-label="Icon"
								track-by="value"
								:disabled="objectStore.isLoading('menu')">
								<template #option="{ label, value }">
									<span class="icon-option">
										<FontAwesomeIcon v-if="value" :icon="['fas', value]" class="icon-preview" />
										{{ label }}
									</span>
								</template>

								<template #selected-option="{ label, value }">
									<span class="icon-option">
										<FontAwesomeIcon v-if="value" :icon="['fas', value]" class="icon-preview" />
										{{ label }}
									</span>
								</template>
							</NcSelect>

							<div v-if="iconMode === 'custom'" class="json-editor">
								<label>Custom Icon (SVG)</label>
								<div :class="`codeMirrorContainer ${getTheme()}`">
									<CodeMirror
										v-model="customIcon"
										:basic="true"
										placeholder="<svg xmlns='http://www.w3.org/2000/svg' ...></svg>"
										:dark="getTheme() === 'dark'"
										:lang="xml()"
										:extensions="[xml()]"
										:tab-size="2"
										style="height: 400px" />
									<NcButton
										class="format-json-button"
										type="secondary"
										size="small"
										@click="formatSVG">
										Format SVG
									</NcButton>
								</div>
							</div>
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
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextField, NcTextArea, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { BTabs, BTab } from 'bootstrap-vue'
import { getTheme } from '../../services/getTheme.js'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import { xml } from '@codemirror/lang-xml'
import CodeMirror from 'vue-codemirror6'
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
		NcTextArea,
		NcSelect,
		NcCheckboxRadioSwitch,
		CodeMirror,
		BTabs,
		BTab,
		FontAwesomeIcon,
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
				iconPrefix: '',
				iconMode: '',
				iconPlacement: '',
				customIcon: '',
				ariaLabel: '',
				groups: [],
				hideAfterLogin: false,
				hideBeforeLogin: false,
				items: [],
				linkMode: 'link',
				value: '',
				valueMode: 'value',
			},
			iconOptions: {
				options: [
					{ label: 'No Icon', value: '' },
					{ label: 'Home', value: 'house' },
					{ label: 'User', value: 'user' },
					{ label: 'Users', value: 'users' },
					{ label: 'Settings', value: 'gear' },
					{ label: 'Search', value: 'magnifying-glass' },
					{ label: 'Dashboard', value: 'chart-line' },
					{ label: 'Info', value: 'info' },
					{ label: 'Info Circle', value: 'circle-info' },
					{ label: 'Question', value: 'question' },
					{ label: 'Help', value: 'circle-question' },
					{ label: 'Phone', value: 'phone' },
					{ label: 'Email', value: 'envelope' },
					{ label: 'Contact', value: 'address-book' },
					{ label: 'Building', value: 'building' },
					{ label: 'Globe', value: 'globe' },
					{ label: 'Map', value: 'map' },
					{ label: 'Location', value: 'location-dot' },
					{ label: 'Key', value: 'key' },
					{ label: 'Lock', value: 'lock' },
					{ label: 'Unlock', value: 'unlock' },
					{ label: 'Shield', value: 'shield' },
					{ label: 'Document', value: 'file' },
					{ label: 'File Text', value: 'file-lines' },
					{ label: 'Folder', value: 'folder' },
					{ label: 'Book', value: 'book' },
					{ label: 'Bookmark', value: 'bookmark' },
					{ label: 'Tag', value: 'tag' },
					{ label: 'Tags', value: 'tags' },
					{ label: 'Star', value: 'star' },
					{ label: 'Heart', value: 'heart' },
					{ label: 'Plus', value: 'plus' },
					{ label: 'Minus', value: 'minus' },
					{ label: 'Check', value: 'check' },
					{ label: 'Times', value: 'xmark' },
					{ label: 'Arrow Right', value: 'arrow-right' },
					{ label: 'Arrow Left', value: 'arrow-left' },
					{ label: 'Arrow Up', value: 'arrow-up' },
					{ label: 'Arrow Down', value: 'arrow-down' },
					{ label: 'Chevron Right', value: 'chevron-right' },
					{ label: 'Chevron Left', value: 'chevron-left' },
					{ label: 'Chevron Up', value: 'chevron-up' },
					{ label: 'Chevron Down', value: 'chevron-down' },
					{ label: 'Menu', value: 'bars' },
					{ label: 'Grid', value: 'table-cells' },
					{ label: 'List', value: 'list' },
					{ label: 'Calendar', value: 'calendar' },
					{ label: 'Clock', value: 'clock' },
					{ label: 'Shopping Cart', value: 'shopping-cart' },
					{ label: 'Credit Card', value: 'credit-card' },
					{ label: 'Money', value: 'dollar-sign' },
					{ label: 'Bell', value: 'bell' },
					{ label: 'Flag', value: 'flag' },
					{ label: 'Camera', value: 'camera' },
					{ label: 'Image', value: 'image' },
					{ label: 'Video', value: 'video' },
					{ label: 'Music', value: 'music' },
					{ label: 'Headphones', value: 'headphones' },
					{ label: 'Microphone', value: 'microphone' },
					{ label: 'Volume Up', value: 'volume-up' },
					{ label: 'Volume Down', value: 'volume-down' },
					{ label: 'Volume Mute', value: 'volume-xmark' },
					{ label: 'WiFi', value: 'wifi' },
					{ label: 'Signal', value: 'signal' },
					{ label: 'Battery', value: 'battery-three-quarters' },
					{ label: 'Power', value: 'power-off' },
					{ label: 'Printer', value: 'print' },
					{ label: 'Download', value: 'download' },
					{ label: 'Upload', value: 'upload' },
					{ label: 'Share', value: 'share' },
					{ label: 'External Link', value: 'external-link' },
					{ label: 'Link', value: 'link' },
					{ label: 'Chain Broken', value: 'link-slash' },
					{ label: 'Copy', value: 'copy' },
					{ label: 'Paste', value: 'paste' },
					{ label: 'Cut', value: 'scissors' },
					{ label: 'Save', value: 'floppy-disk' },
					{ label: 'Edit', value: 'pen' },
					{ label: 'Trash', value: 'trash' },
					{ label: 'Refresh', value: 'arrows-rotate' },
					{ label: 'Sync', value: 'rotate' },
					{ label: 'Filter', value: 'filter' },
					{ label: 'Sort', value: 'sort' },
					{ label: 'Sort Up', value: 'sort-up' },
					{ label: 'Sort Down', value: 'sort-down' },
					{ label: 'Expand', value: 'expand' },
					{ label: 'Compress', value: 'compress' },
					{ label: 'Eye', value: 'eye' },
					{ label: 'Eye Slash', value: 'eye-slash' },
					{ label: 'Toggle On', value: 'toggle-on' },
					{ label: 'Toggle Off', value: 'toggle-off' },
					{ label: 'Lightbulb', value: 'lightbulb' },
					{ label: 'Tools', value: 'tools' },
					{ label: 'Wrench', value: 'wrench' },
					{ label: 'Hammer', value: 'hammer' },
					{ label: 'Cog', value: 'cog' },
					{ label: 'Database', value: 'database' },
					{ label: 'Server', value: 'server' },
					{ label: 'Cloud', value: 'cloud' },
					{ label: 'Truck', value: 'truck' },
					{ label: 'Car', value: 'car' },
					{ label: 'Plane', value: 'plane' },
					{ label: 'Ship', value: 'ship' },
					{ label: 'Train', value: 'train' },
					{ label: 'Bicycle', value: 'bicycle' },
					{ label: 'Walking', value: 'person-walking' },
					{ label: 'Running', value: 'person-running' },
					{ label: 'Handshake', value: 'handshake' },
					{ label: 'Thumbs Up', value: 'thumbs-up' },
					{ label: 'Thumbs Down', value: 'thumbs-down' },
					{ label: 'Fire', value: 'fire' },
					{ label: 'Bolt', value: 'bolt' },
					{ label: 'Sun', value: 'sun' },
					{ label: 'Moon', value: 'moon' },
					{ label: 'Snowflake', value: 'snowflake' },
					{ label: 'Leaf', value: 'leaf' },
					{ label: 'Tree', value: 'tree' },
					{ label: 'Mountain', value: 'mountain' },
					{ label: 'Water', value: 'water' },
				],
				value: { label: 'No Icon', value: '' },
			},
			iconMode: 'standard',
			customIcon: '',
			iconPlacementOptions: {
				options: [
					{ label: 'Left', value: 'left' },
					{ label: 'Right', value: 'right' },
				],
				value: { label: 'Left', value: 'left' },
			},
			groupsOptions: {
				options: [],
				value: [],
				loading: false,
			},
			closeModalTimeout: null,
			linkMode: 'link',
			valueMode: 'value',
			valueMultiRowCache: null,
		}
	},
	computed: {
		menuObject() {
			return objectStore.getActiveObject('menu')
		},
		isFooterPosition() {
			const pos = Number(this.menuObject?.position || 0)
			return pos >= 3 && pos <= 6
		},
		inputValidation() {
			const updatedMenuItem = {
				...this.menuItem,
				icon: this.iconMode === 'standard' ? (this.iconOptions.value?.value || '') : (this.customIcon || ''),
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
			const raw = objectStore.getActiveObject('menuItem')
			this.menuItem = { ...this.menuItem, ...this.normalizeMenuItemFields(raw) }

			// 1) load mode and custom svg from saved data
			this.iconMode = this.menuItem.iconMode || 'standard'
			this.customIcon = this.menuItem.customIcon || ''

			// 2) icon placement
			this.iconPlacementOptions.value
				= this.iconPlacementOptions.options.find(o => o.value === this.menuItem.iconPlacement)
				|| this.iconPlacementOptions.options[0]

			// 3) select icon only if we're in standard mode
			if (this.iconMode === 'standard') {
				const match = this.iconOptions.options.find(o => o.value === this.menuItem.icon)
				this.iconOptions.value = match || this.iconOptions.options[0] || null
			} else {
				this.iconOptions.value = null
			}

			// 4) groups
			if (this.menuItem.groups && this.menuItem.groups.length > 0) {
				this.groupsOptions.value = this.menuItem.groups
			} else {
				this.groupsOptions.value = []
			}

			// initialize linkMode from item if present
			this.linkMode = this.menuItem.linkMode === 'markdown' ? 'markdown' : 'link'
			// initialize valueMode from item if present
			this.valueMode = ['multiRow', 'title'].includes(this.menuItem.valueMode) ? this.menuItem.valueMode : 'value'
			// decode multiline content for textarea editing
			if (this.valueMode === 'multiRow' && typeof this.menuItem.value === 'string') {
				this.menuItem.value = this.decodeMultilineFromStorage(this.menuItem.value)
				// initialize cache with decoded multiline
				this.valueMultiRowCache = this.menuItem.value
			}
		}
	},
	methods: {
		normalizeMenuItemFields(item = {}) {
			return {
				order: Number(item.order || 0),
				name: item.name ?? '',
				link: item.link ?? '',
				description: item.description ?? '',
				ariaLabel: item.ariaLabel ?? '',
				icon: item.icon ?? '',
				iconPrefix: item.iconPrefix ?? 'fas',
				iconMode: item.iconMode ?? 'standard',
				iconPlacement: item.iconPlacement ?? 'left',
				customIcon: item.customIcon ?? '',
				groups: Array.isArray(item.groups) ? item.groups : [],
				hideAfterLogin: !!item.hideAfterLogin,
				hideBeforeLogin: !!item.hideBeforeLogin,
				items: Array.isArray(item.items) ? item.items : [],
				linkMode: item.linkMode ?? 'link',
				value: item.value ?? '',
				valueMode: item.valueMode ?? 'value',
			}
		},
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
		formatSVG() {
			try {
				const input = String(this.customIcon || '').trim()
				const match = input.match(/<svg[\s\S]*?<\/svg>/i)
				if (!match) {
					console.error('No <svg> root element found.')
					return
				}
				const svgString = match[0]

				const parser = new DOMParser()
				const doc = parser.parseFromString(svgString, 'image/svg+xml')
				if (doc.getElementsByTagName('parsererror').length > 0) {
					console.error('Error parsing SVG.')
					return
				}

				let svgEl = doc.documentElement
				if (!svgEl || svgEl.nodeName.toLowerCase() !== 'svg') {
					const found = doc.getElementsByTagName('svg')[0]
					if (!found) {
						console.error('No <svg> element found after parsing.')
						return
					}
					svgEl = found
				}

				this.customIcon = this.prettySvg(svgEl)
			} catch (error) {
				console.error('Error formatting SVG:', error)
			}
		},
		prettySvg(root) {
			const indentUnit = '\t'
			const indent = d => indentUnit.repeat(d)
			const serialize = (node, depth) => {
				if (node.nodeType === 3) {
					const text = node.nodeValue.trim()
					if (!text) return ''
					return indent(depth) + text
				}
				if (node.nodeType === 8) {
					return indent(depth) + `<!--${node.nodeValue}-->`
				}
				if (node.nodeType !== 1) return ''

				const tag = node.tagName
				const attrs = Array.from(node.attributes).map(a => `${a.name}="${a.value}"`).join(' ')
				const open = attrs ? `<${tag} ${attrs}>` : `<${tag}>`
				const children = Array.from(node.childNodes).filter(n => !(n.nodeType === 3 && !n.nodeValue.trim()))

				if (children.length === 0) {
					return indent(depth) + open + '\n' + indent(depth) + `</${tag}>`
				}

				let out = indent(depth) + open
				children.forEach(child => {
					const childStr = serialize(child, depth + 1)
					if (childStr) out += '\n' + childStr
				})
				out += '\n' + indent(depth) + `</${tag}>`
				return out
			}
			return serialize(root, 0)
		},
		/**
		 * Save the menu item (either create new or update existing)
		 * @return {Promise<void>}
		 */
		async saveMenuItem() {
			objectStore.setState('menu', { success: null, error: null, loading: true })

			const menuClone = _.cloneDeep(this.menuObject)
			const activeMenuItem = objectStore.getActiveObject('menuItem')

			// prepare value for save
			let valueForSave = this.menuItem.value
			if (this.valueMode === 'multiRow') {
				valueForSave = this.encodeMultilineForStorage(String(valueForSave || ''))
			} else if (typeof valueForSave === 'string') {
				// ensure single line for non-multiRow modes
				valueForSave = String(valueForSave).replace(/\r?\n/g, ' ').replace(/\\n/g, ' ').trim()
			}

			const updatedMenuItem = {
				...this.menuItem,
				icon: this.iconOptions.value?.value || '',
				// Prefix is fas for now, will change in the future
				iconPrefix: 'fas',
				iconMode: this.iconMode,
				iconPlacement: this.iconPlacementOptions.value?.value || 'left',
				customIcon: this.customIcon,
				groups: this.normalizeGroups(this.groupsOptions.value),
				order: Number(this.menuItem.order) || 0,
				hideBeforeLogin: this.menuItem.hideBeforeLogin,
				linkMode: this.linkMode,
				valueMode: this.valueMode,
				value: valueForSave || null,
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
		setIconMode(mode) {
			this.iconMode = mode
		},
		handleIconSelect(selectedOption) {
			this.iconOptions.value = selectedOption
		},
		setLinkMode(mode) {
			this.linkMode = mode
		},
		setValueMode(mode) {
			const previousMode = this.valueMode
			this.valueMode = mode
			if (mode === 'multiRow' && previousMode !== 'multiRow') {
				// Restore cached multi-row content if available; otherwise decode from storage
				if (this.valueMultiRowCache !== null && this.valueMultiRowCache !== undefined) {
					this.menuItem.value = this.valueMultiRowCache
				} else if (typeof this.menuItem.value === 'string') {
					this.menuItem.value = this.decodeMultilineFromStorage(this.menuItem.value)
				}
			} else if (mode !== 'multiRow' && previousMode === 'multiRow') {
				// Cache current multi-row content before flattening for single-line input
				this.valueMultiRowCache = typeof this.menuItem.value === 'string' ? this.menuItem.value : ''
				if (typeof this.menuItem.value === 'string') {
					this.menuItem.value = this.menuItem.value.replace(/\r?\n/g, ' ').replace(/\\n/g, ' ').trim()
				}
			}
		},
		onSingleLineValueChange(newValue) {
			// User is editing the single-line field: apply value and clear cache
			this.menuItem.value = newValue
			this.valueMultiRowCache = null
		},
		encodeMultilineForStorage(input) {
			if (typeof input !== 'string') return ''
			// Store actual newlines: normalize CRLF, convert literal "\\n" to real newlines
			return input
				.replace(/\r\n/g, '\n')
				.replace(/\r/g, '\n')
				.replace(/\\n/g, '\n')
		},
		decodeMultilineFromStorage(input) {
			if (typeof input !== 'string') return ''
			return input.replace(/\\n/g, '\n')
		},
		normalizeGroups(selected) {
			if (!Array.isArray(selected)) return []
			return selected.map(item => {
				if (typeof item === 'string') return item
				if (item && typeof item === 'object') return item.value ?? String(item.label ?? '')
				return ''
			}).filter(Boolean)
		},
		getTheme() {
			return getTheme()
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

/* CodeMirror */
.codeMirrorContainer {
	margin-block-start: 6px;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}
.codeMirrorContainer :deep(.cm-editor) {
	outline: none !important;
}
.codeMirrorContainer.light > .vue-codemirror {
	border: 1px dotted silver;
}
.codeMirrorContainer.dark > .vue-codemirror {
	border: 1px dotted grey;
}

/* value text color */
/* string */
.codeMirrorContainer.light :deep(.ͼe) {
	color: #448c27;
}
.codeMirrorContainer.dark :deep(.ͼe) {
	color: #88c379;
}

/* boolean */
.codeMirrorContainer.light :deep(.ͼc) {
	color: #221199;
}
.codeMirrorContainer.dark :deep(.ͼc) {
	color: #8d64f7;
}

/* null */
.codeMirrorContainer.light :deep(.ͼb) {
	color: #770088;
}
.codeMirrorContainer.dark :deep(.ͼb) {
	color: #be55cd;
}

/* number */
.codeMirrorContainer.light :deep(.ͼd) {
	color: #d19a66;
}
.codeMirrorContainer.dark :deep(.ͼd) {
	color: #9d6c3a;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}

/* selection color */
.codeMirrorContainer.light :deep(.cm-line)::selection,
.codeMirrorContainer.light :deep(.cm-line) ::selection {
	background-color: #d7eaff !important;
    color: black;
}
.codeMirrorContainer.dark :deep(.cm-line)::selection,
.codeMirrorContainer.dark :deep(.cm-line) ::selection {
	background-color: #8fb3e6 !important;
    color: black;
}

/* string */
.codeMirrorContainer.light :deep(.cm-line .ͼe)::selection {
    color: #2d770f;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼe)::selection {
    color: #104e0c;
}

/* boolean */
.codeMirrorContainer.light :deep(.cm-line .ͼc)::selection {
	color: #221199;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼc)::selection {
	color: #4026af;
}

/* null */
.codeMirrorContainer.light :deep(.cm-line .ͼb)::selection {
	color: #770088;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

/* number */
.codeMirrorContainer.light :deep(.cm-line .ͼd)::selection {
	color: #8c5c2c;
}
.codeMirrorContainer.dark :deep(.cm-line .ͼd)::selection {
	color: #623907;
}
</style>
