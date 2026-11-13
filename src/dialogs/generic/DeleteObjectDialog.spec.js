/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for DeleteObjectDialog.deleteObject(): what it asks
 * the stores to do, in which order, for one object and for a selection, when
 * the deletion works, fails, or fails on the unconfigured publication type
 * that the dialog treats as a success.
 */

import { flushPromises, mount } from '@vue/test-utils'
import DeleteObjectDialog from './DeleteObjectDialog.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

// @nextcloud/auth ships ESM that Jest does not transform; the stores only
// read the request token and current user from it, so stub that boundary.
jest.mock('@nextcloud/auth', () => ({
	getRequestToken: () => 'tok',
	getCurrentUser: () => ({ uid: 'admin', displayName: 'Admin' }),
}))

const UNCONFIGURED = new Error('Invalid configuration for object type: publication')

let log

/**
 * Mount the dialog for an object type, with the store calls logged.
 *
 * @param {object} properties The dialog properties.
 * @param {Function} deletion What objectStore.deleteObject answers.
 * @return {object} The wrapper.
 */
function mountDialog(properties, deletion) {
	navigationStore.dialog = 'deleteObject'
	navigationStore.dialogProperties = properties
	jest.spyOn(objectStore, 'deleteObject').mockImplementation((...args) => {
		log.push(['deleteObject', ...args])
		return deletion(...args)
	})
	jest.spyOn(objectStore, 'setState').mockImplementation((...args) => log.push(['setState', ...args]))
	jest.spyOn(objectStore, 'fetchCollection').mockImplementation((...args) => log.push(['fetchCollection', ...args]))
	jest.spyOn(catalogStore, 'fetchPublications').mockImplementation((...args) => log.push(['fetchPublications', ...args]))
	jest.spyOn(navigationStore, 'setDialog').mockImplementation((...args) => log.push(['setDialog', ...args]))
	jest.spyOn(console, 'error').mockImplementation((...args) => log.push(['console.error', args[0], String(args[1])]))
	return mount(DeleteObjectDialog, {
		global: { mocks: { t: (app, text) => text }, stubs: { NcDialog: true, NcButton: true, NcNoteCard: true, NcLoadingIcon: true } },
	})
}

/**
 * Delete, then run every timer, logging what happened.
 *
 * @param {object} wrapper The dialog.
 * @return {Promise<Array>} The log, with the loading flag after each phase.
 */
async function run(wrapper) {
	let thrown = null
	try {
		wrapper.vm.deleteObject()
	} catch (error) {
		thrown = error.constructor.name
	}
	log.push(['loading after call', wrapper.vm.loading, thrown])
	await flushPromises()
	log.push(['loading after settle', wrapper.vm.loading, wrapper.vm.closeTimeout !== null])
	jest.runAllTimers()
	await flushPromises()
	log.push(['loading after timers', wrapper.vm.loading])
	return log
}

beforeEach(() => {
	jest.useFakeTimers()
	log = []
	objectStore.activeObjects = {
		publication: { id: 'pub-1', '@self': { schema: 7, register: 3 } },
		theme: { id: 'theme-1' },
	}
})

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
	delete objectStore.getSelectedObjects
})

describe('DeleteObjectDialog.deleteObject, one object', () => {
	for (const [name, type, deletion] of [
		['works', 'publication', () => Promise.resolve({})],
		['works for another type', 'theme', () => Promise.resolve({})],
		['unconfigured publication', 'publication', () => Promise.reject(UNCONFIGURED)],
		['unconfigured message on another type', 'theme', () => Promise.reject(UNCONFIGURED)],
		['fails', 'publication', () => Promise.reject(new Error('boom'))],
		['fails without a message', 'publication', () => Promise.reject({})],
	]) {
		it(name, async () => {
			expect(await run(mountDialog({ objectType: type, dialogTitle: 'X' }, deletion))).toMatchSnapshot()
		})
	}

	it('does nothing without an active object', async () => {
		expect(await run(mountDialog({ objectType: 'listing' }, () => Promise.resolve({})))).toMatchSnapshot()
	})
})

describe('DeleteObjectDialog.deleteObject, a selection', () => {
	for (const [name, type, deletion] of [
		['works', 'theme', () => Promise.resolve({})],
		['one unconfigured publication', 'publication', (type, id) => (id === 'b' ? Promise.reject(UNCONFIGURED) : Promise.resolve({}))],
		['one fails', 'publication', (type, id) => (id === 'b' ? Promise.reject(new Error('boom')) : Promise.resolve({}))],
	]) {
		it(name, async () => {
			const wrapper = mountDialog({ objectType: type, isMultiple: true }, deletion)
			objectStore.getSelectedObjects = (t) => {
				log.push(['getSelectedObjects', t])
				return [{ id: 'a' }, { id: 'b' }]
			}
			expect(await run(wrapper)).toMatchSnapshot()
		})
	}

	it('does nothing with an empty selection, and throws without a selection getter', async () => {
		const wrapper = mountDialog({ objectType: 'theme', isMultiple: true }, () => Promise.resolve({}))
		objectStore.getSelectedObjects = () => []
		expect(await run(wrapper)).toMatchSnapshot()
		delete objectStore.getSelectedObjects
		log = []
		expect(await run(wrapper)).toMatchSnapshot()
	})
})
