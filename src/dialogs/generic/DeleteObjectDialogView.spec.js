/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behaviour test for what DeleteObjectDialog renders: its title, question,
 * progress, success and error cards for one object and for a selection, the
 * unconfigured-publication error it hides, and what Close and Cancel do.
 * DeleteObjectDialog.spec.js covers the deletion itself.
 */

import { enableAutoUnmount, mount } from '@vue/test-utils'
import { h } from 'vue'
import DeleteObjectDialog from './DeleteObjectDialog.vue'
import { navigationStore, objectStore } from '../../store/store.js'

// The object store imports @nextcloud/auth, whose ESM-only dependency Jest
// cannot parse; the dialog never reaches it.
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))

const UNCONFIGURED = 'Invalid configuration for object type: publication'

// Unmount every dialog after its test, so no stale watcher reacts to the
// next test's store changes.
enableAutoUnmount(afterEach)

// Renders the dialog's name, its content and its actions.
const NcDialog = {
	name: 'NcDialog',
	props: { name: { type: String, default: '' }, canClose: { type: Boolean, default: true } },
	render() {
		return h('section', { 'data-name': this.name, 'data-can-close': String(this.canClose) }, [
			h('main', this.$slots.default?.()),
			h('footer', this.$slots.actions?.()),
		])
	},
}

/**
 * Open the dialog for a type and mount it.
 *
 * @param {object} properties The dialog properties.
 * @return {object} The wrapper.
 */
function mountDialog(properties) {
	navigationStore.setDialog('deleteObject', properties)
	return mount(DeleteObjectDialog, {
		global: {
			stubs: { NcDialog },
			mocks: { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]) },
		},
	})
}

/**
 * The note cards the dialog shows, as [type, text] pairs.
 *
 * @param {object} wrapper The dialog.
 * @return {Array<Array<string>>} The cards.
 */
function cards(wrapper) {
	return wrapper.findAll('.nc-note-card-stub').map((card) => [card.classes()[1].replace('nc-note-card-stub--', ''), card.text()])
}

/**
 * The texts of the dialog's footer buttons.
 *
 * @param {object} wrapper The dialog.
 * @return {Array<string>} The button texts, in order.
 */
function buttons(wrapper) {
	return wrapper.findAll('footer button').map((button) => button.text())
}

const ONE = { objectType: 'theme', dialogTitle: 'Theme' }
const MANY = { objectType: 'theme', dialogTitle: 'Theme', isMultiple: true }

beforeEach(() => {
	for (const type of ['theme', 'publication']) {
		objectStore.setState(type, { success: null, error: null })
		objectStore.setLoading(type, false)
	}
	objectStore.activeObjects = {
		...objectStore.activeObjects,
		theme: { id: 't-1', name: 'Wonen', title: 'Titel' },
		publication: { id: 'p-1', title: 'Besluit' },
	}
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
	navigationStore.setDialog(false)
})

it('renders nothing while another dialog is open', () => {
	const wrapper = mountDialog(ONE)
	navigationStore.setDialog('copyObject', ONE)
	return wrapper.vm.$nextTick().then(() => {
		expect(wrapper.find('section').exists()).toBe(false)
	})
})

describe('asking', () => {
	it('names one object by its name, and cannot be closed from the corner', () => {
		const wrapper = mountDialog(ONE)
		expect(wrapper.find('section').attributes()).toMatchObject({ 'data-name': 'Delete Theme', 'data-can-close': 'false' })
		expect(wrapper.find('main').text()).toBe('Do you want to delete Wonen? This action cannot be undone.')
		expect(cards(wrapper)).toEqual([])
		expect(buttons(wrapper)).toEqual(['Cancel', 'Delete'])
	})

	it('falls back to the title for an object without a name', () => {
		const wrapper = mountDialog({ objectType: 'publication', dialogTitle: 'Publication' })
		expect(wrapper.find('main').text()).toBe('Do you want to delete Besluit? This action cannot be undone.')
	})

	it('asks about the selection in the plural, lower-cased', () => {
		const wrapper = mountDialog(MANY)
		expect(wrapper.find('section').attributes('data-name')).toBe('Delete Themes')
		expect(wrapper.find('main').text()).toBe('Do you want to delete the selected themes? This action cannot be undone.')
	})

	it('disables the delete button and shows a spinner in it while it deletes', async () => {
		jest.spyOn(objectStore, 'deleteObject').mockReturnValue(new Promise(() => {}))
		const wrapper = mountDialog(ONE)
		const idle = wrapper.findAll('footer button')[1]
		expect(idle.attributes('disabled')).toBeUndefined()
		expect(idle.find('.nc-loading-icon-stub').exists()).toBe(false)
		await idle.trigger('click')
		const busy = wrapper.findAll('footer button')[1]
		expect(busy.attributes('disabled')).toBeDefined()
		expect(busy.find('.nc-loading-icon-stub').exists()).toBe(true)
	})

	it('closes without deleting when Cancel is pressed', async () => {
		const setDialog = jest.spyOn(navigationStore, 'setDialog')
		const deleteObject = jest.spyOn(objectStore, 'deleteObject').mockResolvedValue({})
		const wrapper = mountDialog(ONE)
		await wrapper.findAll('footer button')[0].trigger('click')
		expect(setDialog).toHaveBeenLastCalledWith(false)
		expect(deleteObject).not.toHaveBeenCalled()
	})
})

describe('deleting', () => {
	it.each([
		[ONE, 'Theme is being deleted...'],
		[MANY, 'Themes are being deleted...'],
	])('shows the progress and only a close button (%#)', (properties, text) => {
		objectStore.setLoading('theme', true)
		const wrapper = mountDialog(properties)
		expect(wrapper.find('.loading-status').text()).toBe(text)
		expect(wrapper.find('main').text()).not.toContain('Do you want')
		expect(buttons(wrapper)).toEqual(['Close'])
	})
})

describe('after a success', () => {
	it.each([
		[ONE, 'Theme successfully deleted'],
		[MANY, 'Themes successfully deleted'],
	])('shows the success card and only a close button (%#)', (properties, text) => {
		objectStore.setState('theme', { success: true, error: null })
		const wrapper = mountDialog(properties)
		expect(cards(wrapper)).toEqual([['success', text]])
		expect(buttons(wrapper)).toEqual(['Close'])
	})

	it('closes and clears the state when Close is pressed', async () => {
		objectStore.setState('theme', { success: true, error: null })
		const setDialog = jest.spyOn(navigationStore, 'setDialog')
		const wrapper = mountDialog(ONE)
		await wrapper.find('footer button').trigger('click')
		expect(setDialog).toHaveBeenLastCalledWith(false)
		expect(objectStore.getState('theme')).toEqual({ success: null, error: null })
	})
})

describe('after an error', () => {
	it.each([
		[ONE, 'Something went wrong while deleting theme'],
		[MANY, 'Something went wrong while deleting themes'],
	])('shows what went wrong and the error, and keeps asking (%#)', (properties, text) => {
		objectStore.setState('theme', { success: false, error: 'Boom' })
		const wrapper = mountDialog(properties)
		expect(cards(wrapper)).toEqual([['error', text], ['error', 'Boom']])
		expect(wrapper.find('main').text()).toContain('Do you want to delete')
		expect(buttons(wrapper)).toEqual(['Cancel', 'Delete'])
	})

	it('hides the unconfigured-publication error, which it counts as done', () => {
		objectStore.setState('publication', { success: false, error: UNCONFIGURED })
		const wrapper = mountDialog({ objectType: 'publication', dialogTitle: 'Publication' })
		expect(cards(wrapper)).toEqual([])
		expect(wrapper.text()).not.toContain(UNCONFIGURED)
	})
})
