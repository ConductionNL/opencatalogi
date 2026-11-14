/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behaviour test for how CopyObjectDialog names the object it asks about,
 * and when it looks up the source schema's name field that copyObject
 * renames. CopyObjectDialog.spec.js covers the copying itself.
 */

import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { h } from 'vue'
import CopyObjectDialog from './CopyObjectDialog.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

// The object store imports @nextcloud/auth, whose ESM-only dependency Jest
// cannot parse; the dialog never reaches it.
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token' }))

jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

// Unmount every dialog after its test, so no stale watcher reacts to the
// next test's store changes.
enableAutoUnmount(afterEach)

// Renders the dialog's content and its actions.
const NcDialog = {
	name: 'NcDialog',
	render() {
		return h('section', [h('main', this.$slots.default?.()), h('footer', this.$slots.actions?.())])
	},
}

const ONE = { objectType: 'publication', dialogTitle: 'Publication' }

/**
 * Open the dialog on an active publication and mount it.
 *
 * @param {object|null} active The active publication.
 * @param {object} properties The dialog properties.
 * @return {object} The wrapper.
 */
function mountDialog(active, properties = ONE) {
	objectStore.activeObjects = { ...objectStore.activeObjects, publication: active }
	navigationStore.setDialog('copyObject', properties)
	return mount(CopyObjectDialog, {
		global: {
			stubs: { NcDialog },
			mocks: { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key]) },
		},
	})
}

/**
 * Press the dialog's Copy button.
 *
 * @param {object} wrapper The dialog.
 * @return {Promise<void>} When the press has settled.
 */
async function pressCopy(wrapper) {
	const buttons = wrapper.findAll('footer button')
	await buttons[buttons.length - 1].trigger('click')
	await flushPromises()
}

/**
 * A fetch answering a schema with a configuration.
 *
 * @param {object} configuration The schema's configuration.
 * @return {Function} The fetch mock.
 */
function schemaFetch(configuration) {
	return jest.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ configuration }) }))
}

beforeEach(() => {
	jest.useFakeTimers()
	global.fetch = jest.fn(() => Promise.resolve({ ok: false }))
	objectStore.setState('publication', { success: null, error: null })
	objectStore.setLoading('publication', false)
	objectStore.copyObject = jest.fn(() => Promise.resolve())
	catalogStore.fetchPublications = jest.fn()
})

afterEach(() => {
	jest.runOnlyPendingTimers()
	jest.useRealTimers()
	navigationStore.setDialog(false)
})

describe('the name it asks about', () => {
	it.each([
		['the resolved @self name first', { id: 'i', '@self': { name: 'Self', id: 's' }, name: 'Name', title: 'Title' }, 'Self'],
		['then the name', { id: 'i', '@self': { id: 's' }, name: 'Name', title: 'Title' }, 'Name'],
		['then the title', { id: 'i', '@self': { id: 's' }, title: 'Title' }, 'Title'],
		['then the @self id', { id: 'i', '@self': { id: 's' } }, 's'],
		['then the id', { id: 'i' }, 'i'],
		['and nothing at all', {}, ''],
	])('uses %s', (label, active, name) => {
		expect(mountDialog(active).find('main').text()).toBe(`Do you want to copy ${name}?`)
	})

	it('asks with an empty name when there is no active object', () => {
		expect(mountDialog(null).find('main').text()).toBe('Do you want to copy ?')
	})
})

describe('the schema lookup', () => {
	it('fetches the source schema from OpenRegister once, by its id', async () => {
		global.fetch = schemaFetch({ objectNameField: 'naam' })
		await flushPromises()
		const wrapper = mountDialog({ id: 'p-1', '@self': { schema: 0 } })
		await flushPromises()
		expect(global.fetch.mock.calls).toEqual([
			['/index.php/apps/openregister/api/schemas/0', { method: 'GET', headers: { 'Content-Type': 'application/json' } }],
		])
		await pressCopy(wrapper)
		expect(objectStore.copyObject.mock.calls).toEqual([['publication', 'p-1', 'naam']])
	})

	it('does not fetch the schema for a selection or an object without one', async () => {
		mountDialog({ id: 'p-1', '@self': { schema: 7 } }, { ...ONE, isMultiple: true })
		mountDialog({ id: 'p-1', '@self': {} })
		mountDialog({ id: 'p-1', '@self': { schema: null } })
		await flushPromises()
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it.each([
		['a refused fetch', () => jest.fn(() => Promise.resolve({ ok: false }))],
		['a fetch that throws', () => jest.fn(() => Promise.reject(new Error('offline')))],
		['a schema without a name field', () => schemaFetch({})],
		['an empty name field', () => schemaFetch({ objectNameField: '' })],
		['a name field that is not a string', () => schemaFetch({ objectNameField: ['naam'] })],
	])('copies without a name field after %s', async (label, makeFetch) => {
		global.fetch = makeFetch()
		const wrapper = mountDialog({ id: 'p-2', '@self': { schema: `s-${label}` } })
		await flushPromises()
		expect(global.fetch).toHaveBeenCalledTimes(1)
		await pressCopy(wrapper)
		expect(objectStore.copyObject.mock.calls).toEqual([['publication', 'p-2', null]])
	})
})
