/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the two selection lists, `SelectAttachmentsList`
 * and `SelectedObjectsList`: the markup each renders (title, items, subtitle,
 * error, disabled state, remove button, empty state) and what removing an
 * item does to the store's selection.
 */

import { mount } from '@vue/test-utils'
import SelectAttachmentsList from './SelectAttachmentsList.vue'
import SelectedObjectsList from './SelectedObjectsList.vue'
import { objectStore } from '../store/store.js'

const FILES = [
	{ id: 1, name: 'besluit.pdf', size: 2048 },
	{ id: 2, title: 'bijlage', size: 0 },
	{ id: 3, name: 'groot.pdf', size: 5 * 1024 * 1024 },
]

const OBJECTS = [
	{ id: 'a', name: 'Object A', schema: { title: 'Besluit' }, summary: 'Sum A' },
	{ '@self': { id: 'b', name: 'Object B', schema: 7 }, title: 'ignored' },
	{ id: 'c', title: 'Object C', schema: '' },
]

const GLOBAL = {
	mocks: {
		t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]),
	},
}

/**
 * Mount a selection list with the translation function in place.
 *
 * @param {object} component The list component.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountList(component, props) {
	return mount(component, { props, global: GLOBAL })
}

beforeEach(() => {
	objectStore.$reset()
	objectStore.activeObjects = { publication: { id: 'pub-1' } }
	objectStore.relatedData = { publication: { files: { results: FILES } } }
	objectStore.objectErrors = { b: 'failed b', 3: 'failed 3' }
	objectStore.settings = {
		availableRegisters: [{ id: 'r', title: 'R', schemas: [{ id: '7', title: 'Document' }] }],
	}
})

afterEach(() => {
	jest.restoreAllMocks()
	objectStore.$reset()
})

describe('SelectAttachmentsList', () => {
	it('renders the attachments it is given', () => {
		const wrapper = mountList(SelectAttachmentsList, { attachments: [1, 2, 3, 9] })
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('renders the empty state, and nothing without an active publication', () => {
		expect(mountList(SelectAttachmentsList, { attachments: [] }).html()).toMatchSnapshot()
		objectStore.activeObjects = {}
		expect(mountList(SelectAttachmentsList, { attachments: [1], title: 'T', emptyTitle: 'E', emptyDescription: 'D' }).html()).toMatchSnapshot()
	})

	it('hides the remove buttons on request', () => {
		const wrapper = mountList(SelectAttachmentsList, { attachments: [1], showRemove: false })
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('removes the clicked attachment from the store selection', async () => {
		objectStore.selectedAttachments = [1, 2, 3]
		const set = jest.spyOn(objectStore, 'setSelectedAttachments').mockImplementation(() => {})
		const wrapper = mountList(SelectAttachmentsList, { attachments: [1, 2, 3] })
		await wrapper.findAll('button')[1].trigger('click')
		expect(set.mock.calls).toEqual([[[1, 3]]])
	})
})

describe('SelectedObjectsList', () => {
	it('renders the objects it is given, with schema subtitles', () => {
		const wrapper = mountList(SelectedObjectsList, { objects: OBJECTS })
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('renders another subtitle field, a hidden title and disabled items', () => {
		const wrapper = mountList(SelectedObjectsList, {
			objects: OBJECTS,
			hideTitle: true,
			subtitleAttribute: 'summary',
			isDisabled: (obj) => obj.id === 'a' || obj.id === 'c',
			disabledReason: (obj) => (obj.id === 'a' ? 'not allowed' : ''),
		})
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('renders the empty state', () => {
		const wrapper = mountList(SelectedObjectsList, { objects: [], title: 'Selected Publications', showRemove: false })
		expect(wrapper.html()).toMatchSnapshot()
	})

	it('removes the clicked object from both store selections', async () => {
		objectStore.selectedObjects = [{ id: 'a' }, { '@self': { id: 'b' } }]
		objectStore.selectedAttachments = ['b', 'x']
		const setObjects = jest.spyOn(objectStore, 'setSelectedObjects').mockImplementation(() => {})
		const setAttachments = jest.spyOn(objectStore, 'setSelectedAttachments').mockImplementation(() => {})
		const wrapper = mountList(SelectedObjectsList, { objects: OBJECTS })
		await wrapper.findAll('button')[1].trigger('click')
		expect(setObjects.mock.calls).toEqual([[[{ id: 'a' }]]])
		expect(setAttachments.mock.calls).toEqual([[['x']]])
	})
})
