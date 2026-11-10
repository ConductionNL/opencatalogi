/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for CopyObjectDialog: what it renders for one object
 * and for a selection while closed, asking, copying, after a success and
 * after an error, and what pressing Copy does.
 */

import { h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import CopyObjectDialog from './CopyObjectDialog.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

// Renders the dialog's name, its content and its actions.
const NcDialog = {
	name: 'NcDialog',
	props: { name: { type: String, default: '' }, canClose: { type: Boolean, default: true } },
	render() {
		return h('section', { class: 'nc-dialog-stub', 'data-name': this.name, 'data-can-close': String(this.canClose) }, [
			this.$slots.default?.(),
			h('footer', this.$slots.actions?.()),
		])
	},
}

const CASES = [
	{ label: 'one publication', properties: { objectType: 'publication', dialogTitle: 'Publication' } },
	{ label: 'a selection of catalogs', properties: { objectType: 'catalog', dialogTitle: 'Catalog', isMultiple: true } },
]

/**
 * Mount the dialog with the global t() the template calls.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(CopyObjectDialog, {
		global: {
			stubs: { NcDialog },
			mocks: { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]) },
		},
	})
}

beforeEach(() => {
	jest.useRealTimers()
	global.fetch = jest.fn(() => Promise.resolve({ ok: false }))
	navigationStore.setDialog(false)
	for (const type of ['publication', 'catalog']) {
		objectStore.setState(type, { success: null, error: null })
		objectStore.setLoading(type, false)
	}
	objectStore.activeObjects = {
		...objectStore.activeObjects,
		publication: { id: 'p-1', title: 'Besluit' },
		catalog: { id: 'c-1', '@self': { name: 'Wonen', id: 'c-1' } },
	}
	objectStore.getSelectedObjects = jest.fn((type) => [{ id: `${type}-1` }, { id: `${type}-2` }])
	objectStore.copyObject = jest.fn(() => Promise.resolve())
	objectStore.fetchCollection = jest.fn()
	catalogStore.fetchPublications = jest.fn()
})

it('renders nothing while another dialog is open', () => {
	navigationStore.setDialog('somethingElse')
	expect(mountDialog().html()).toMatchSnapshot()
})

describe.each(CASES)('$label', ({ properties }) => {
	it('asks before it copies', () => {
		navigationStore.setDialog('copyObject', properties)
		expect(mountDialog().html()).toMatchSnapshot()
	})

	it('shows that it is copying', () => {
		navigationStore.setDialog('copyObject', properties)
		objectStore.setLoading(properties.objectType, true)
		expect(mountDialog().html()).toMatchSnapshot()
	})

	it('shows a success with only a close button', () => {
		navigationStore.setDialog('copyObject', properties)
		objectStore.setState(properties.objectType, { success: true, error: null })
		expect(mountDialog().html()).toMatchSnapshot()
	})

	it('shows an error beside the question', () => {
		navigationStore.setDialog('copyObject', properties)
		objectStore.setState(properties.objectType, { success: false, error: 'Boom' })
		expect(mountDialog().html()).toMatchSnapshot()
	})

	it('copies, refreshes the list, then clears the state and closes after two seconds', async () => {
		jest.useFakeTimers()
		navigationStore.setDialog('copyObject', properties)
		const buttons = mountDialog().findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		await flushPromises()
		expect([
			objectStore.copyObject.mock.calls,
			objectStore.fetchCollection.mock.calls,
			catalogStore.fetchPublications.mock.calls.length,
		]).toMatchSnapshot()
		objectStore.setState(properties.objectType, { success: true, error: 'kept' })
		jest.advanceTimersByTime(1999)
		expect([navigationStore.dialog, objectStore.getState(properties.objectType)]).toMatchSnapshot()
		jest.advanceTimersByTime(1)
		expect([navigationStore.dialog, objectStore.getState(properties.objectType)]).toMatchSnapshot()
	})

	it('does nothing without anything to copy', async () => {
		navigationStore.setDialog('copyObject', properties)
		objectStore.activeObjects = { ...objectStore.activeObjects, publication: { title: 'no id' }, catalog: null }
		objectStore.getSelectedObjects = jest.fn(() => [])
		const wrapper = mountDialog()
		await flushPromises()
		const buttons = wrapper.findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		expect(objectStore.copyObject).not.toHaveBeenCalled()
	})
})

it('renames the schema\'s name field when the schema configures one', async () => {
	global.fetch = jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ configuration: { objectNameField: 'contact.naam' } }) }))
	objectStore.activeObjects = { ...objectStore.activeObjects, publication: { id: 'p-1', '@self': { schema: 7 } } }
	navigationStore.setDialog('copyObject', { objectType: 'publication', dialogTitle: 'Publication' })
	const wrapper = mountDialog()
	await flushPromises()
	const buttons = wrapper.findAll('button')
	await buttons[buttons.length - 1].trigger('click')
	expect([global.fetch.mock.calls, objectStore.copyObject.mock.calls]).toMatchSnapshot()
})
