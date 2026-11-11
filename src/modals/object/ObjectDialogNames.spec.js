/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the object name `DownloadObject.vue` and
 * `LockObject.vue` show: the `@self` name, then the object's own name, then
 * the `@self` title, then the id, and (in the dialog titles) "Publication"
 * when the object has none of those.
 */

import { mount } from '@vue/test-utils'
import DownloadObject from './DownloadObject.vue'
import LockObject from './LockObject.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@codemirror/lang-json', () => ({
	json: () => ({}),
	jsonParseLinter: () => () => [],
}))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))

jest.mock('@nextcloud/vue', () => {
	const plain = { template: '<div><slot /></div>' }
	return {
		NcButton: plain,
		NcDialog: {
			props: ['name'],
			template: '<div class="dialog" :data-name="name"><slot /><slot name="actions" /></div>',
		},
		NcLoadingIcon: plain,
		NcNoteCard: plain,
		NcTextField: plain,
	}
})

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: null, setModal: jest.fn() },
	objectStore: {
		objectItem: null,
		downloadObject: jest.fn(),
		lockObject: jest.fn(),
	},
}))

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key]))

const cases = [
	['the @self name', { id: 'id-1', name: 'Own', '@self': { name: 'Self name', title: 'Self title' } }, 'Self name'],
	['the own name', { id: 'id-1', name: 'Own', '@self': { title: 'Self title' } }, 'Own'],
	['the @self title', { id: 'id-1', '@self': { title: 'Self title' } }, 'Self title'],
	['the id', { id: 'id-1', '@self': {} }, 'id-1'],
]

/**
 * Mount `component` as the open modal `modal` on `item`.
 *
 * @param {object} component The modal component.
 * @param {{modal: string, item: object}} open Which modal is open, on what.
 * @return {object} The wrapper.
 */
function mountOpen(component, { modal, item }) {
	navigationStore.modal = modal
	objectStore.objectItem = item
	// DownloadObject's editor binds `objectItem.object`, which the component
	// does not declare; the mock lets its template render.
	return mount(component, { global: { mocks: { t, objectItem: { object: '' } } } })
}

describe('DownloadObject and LockObject object names', () => {
	it.each(cases)('DownloadObject titles the dialog with %s', (label, item, expected) => {
		const wrapper = mountOpen(DownloadObject, { modal: 'downloadObject', item })

		expect(wrapper.find('.dialog').attributes('data-name')).toBe(`Download ${expected}`)
	})

	it.each(cases)('LockObject titles and explains with %s', (label, item, expected) => {
		const wrapper = mountOpen(LockObject, { modal: 'lockObject', item })

		expect(wrapper.find('.dialog').attributes('data-name')).toBe(`Lock ${expected}`)
		expect(wrapper.find('p').text()).toContain(`Do you want to lock ${expected}?`)
	})

	it('falls back to Publication in the titles and to undefined in the lock text', () => {
		const lock = mountOpen(LockObject, { modal: 'lockObject', item: { '@self': {} } })

		expect(lock.find('.dialog').attributes('data-name')).toBe('Lock Publication')
		expect(lock.find('p').text()).toContain('Do you want to lock undefined?')

		const download = mountOpen(DownloadObject, { modal: 'downloadObject', item: { '@self': {} } })
		expect(download.find('.dialog').attributes('data-name')).toBe('Download Publication')
	})

	it('falls back to the id in the download title', () => {
		const wrapper = mountOpen(DownloadObject, { modal: 'downloadObject', item: { id: 'id-9' } })

		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Download id-9')
	})
})
