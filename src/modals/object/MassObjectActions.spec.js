/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the mass lock, unlock and validate dialogs. Each hands a
 * copy of the selection to its store action, shows success and refreshes the
 * publications when anything succeeded, names the failures, closes itself two
 * seconds after a clean run, and reports a throwing store.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import MassLockObjects from './MassLockObjects.vue'
import MassUnlockObjects from './MassUnlockObjects.vue'
import MassValidateObjects from './MassValidateObjects.vue'

jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: { setDialog: jest.fn() },
	objectStore: {
		selectedObjects: [],
		massLockObjects: jest.fn(),
		massUnlockObjects: jest.fn(),
		massValidateObjects: jest.fn(),
	},
}))

const { catalogStore, navigationStore, objectStore } = jest.requireMock('../../store/store.js')

const DIALOGS = [
	{ name: 'lock', component: MassLockObjects, action: 'massLockObjects', run: 'lockObjects', verb: 'lock', doing: 'locking', past: 'locked', title: 'Lock' },
	{ name: 'unlock', component: MassUnlockObjects, action: 'massUnlockObjects', run: 'unlockObjects', verb: 'unlock', doing: 'unlocking', past: 'unlocked', title: 'Unlock' },
	{ name: 'validate', component: MassValidateObjects, action: 'massValidateObjects', run: 'validateObjects', verb: 'validate', doing: 'validating', past: 'validated', title: 'Validate' },
]

/**
 * Mount a dialog with its Nextcloud parts stubbed.
 *
 * @param {object} component The dialog.
 * @return {object} The wrapper.
 */
function open(component) {
	return shallowMount(component, {
		global: {
			mocks: { t: (app, text, vars) => text.replace('{type}', vars?.type ?? '') },
			stubs: {
				NcDialog: { props: ['name'], template: '<div class="dialog"><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
				NcNoteCard: { props: ['type'], template: '<div :class="\'note-\' + type"><slot /></div>' },
			},
		},
	})
}

beforeEach(() => {
	jest.useFakeTimers()
	catalogStore.fetchPublications.mockClear()
	navigationStore.setDialog.mockClear()
	objectStore.selectedObjects = [{ id: 'a' }, { id: 'b' }]
})

afterEach(() => {
	jest.useRealTimers()
})

describe.each(DIALOGS)('the mass $name dialog', ({ component, action, run, verb, doing, past, title }) => {
	beforeEach(() => {
		objectStore[action].mockReset()
	})

	it('names the count in its title and remembers the selection size', () => {
		const wrapper = open(component)
		expect(wrapper.find('h2').text()).toBe(`${title} 2 publications`)
		expect(wrapper.vm.originalSelectedCount).toBe(2)

		objectStore.selectedObjects = [{ id: 'a' }]
		expect(open(component).find('h2').text()).toBe(`${title} publication`)
	})

	it('runs on a copy of the selection, refreshes, shows success and closes after two seconds', async () => {
		objectStore[action].mockResolvedValue({ successful: ['a', 'b'], failed: [] })
		const wrapper = open(component)

		await wrapper.vm[run]()
		await flushPromises()

		const sent = objectStore[action].mock.calls[0][0]
		expect(sent).toEqual([{ id: 'a' }, { id: 'b' }])
		expect(sent).not.toBe(objectStore.selectedObjects)
		expect(wrapper.vm.success).toBe(true)
		expect(wrapper.vm.error).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.note-success').text()).toBe(`Publications successfully ${past}`)

		jest.advanceTimersByTime(1999)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		jest.advanceTimersByTime(1)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it.each([
		[{ successful: ['a'], failed: ['b'] }, true, `Failed to ${verb} 1 object`, 1],
		[{ successful: [], failed: ['a', 'b'] }, null, `Failed to ${verb} 2 objects`, 0],
	])('reports %j and stays open', async (result, success, error, refreshes) => {
		objectStore[action].mockResolvedValue(result)
		const wrapper = open(component)

		await wrapper.vm[run]()
		await flushPromises()

		expect(wrapper.vm.success).toBe(success)
		expect(wrapper.vm.error).toBe(error)
		expect(wrapper.find('.note-error').text()).toBe(error)
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(refreshes)
		jest.runAllTimers()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('reports a throwing store with its message, or a default one', async () => {
		objectStore[action].mockRejectedValueOnce(new Error('Boom'))
		const wrapper = open(component)
		await wrapper.vm[run]()
		expect(wrapper.vm.success).toBe(false)
		expect(wrapper.vm.error).toBe('Boom')
		expect(wrapper.vm.loading).toBe(false)

		objectStore[action].mockRejectedValueOnce({})
		await wrapper.vm[run]()
		expect(wrapper.vm.error).toBe(`An error occurred while ${doing} objects`)
		expect(catalogStore.fetchPublications).not.toHaveBeenCalled()
	})

	it('closes at once, cancelling a pending close, and on the dialog closing', async () => {
		objectStore[action].mockResolvedValue({ successful: ['a'], failed: [] })
		const wrapper = open(component)
		await wrapper.vm[run]()
		await flushPromises()
		expect(wrapper.vm.closeModalTimeout).not.toBe(null)

		wrapper.vm.closeDialog()
		expect(wrapper.vm.closeModalTimeout).toBe(null)
		expect(navigationStore.setDialog).toHaveBeenCalledTimes(1)
		jest.runAllTimers()
		expect(navigationStore.setDialog).toHaveBeenCalledTimes(1)

		wrapper.vm.handleDialogClose(true)
		expect(navigationStore.setDialog).toHaveBeenCalledTimes(1)
		wrapper.vm.handleDialogClose(false)
		expect(navigationStore.setDialog).toHaveBeenCalledTimes(2)
	})
})

describe('the mass lock dialog', () => {
	it('sends the process and duration, and null for empty ones', async () => {
		objectStore.massLockObjects.mockReset().mockResolvedValue({ successful: ['a'], failed: [] })
		const wrapper = open(MassLockObjects)
		// A `<script setup>` block makes `wrapper.vm` a proxy over the setup state,
		// so the options data the method reads is reached through `$data`.
		Object.assign(wrapper.vm.$data, { process: 'Review', duration: 60 })
		await wrapper.vm.lockObjects()
		expect(objectStore.massLockObjects).toHaveBeenLastCalledWith([{ id: 'a' }, { id: 'b' }], 'Review', 60)

		Object.assign(wrapper.vm.$data, { process: '', duration: '' })
		await wrapper.vm.lockObjects()
		expect(objectStore.massLockObjects).toHaveBeenLastCalledWith([{ id: 'a' }, { id: 'b' }], null, null)
	})

	it('defaults to an hour', () => {
		expect(open(MassLockObjects).vm.$data.duration).toBe(3600)
	})
})
