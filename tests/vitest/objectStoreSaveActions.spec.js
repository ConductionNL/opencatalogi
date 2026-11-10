/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the object store's write actions (saveObject,
 * createObject, updateObject): the request each sends, what it returns or
 * throws, the loading, error and state keys it sets, and the order of the
 * follow-up calls. The clock is frozen so the `@self.updated` stamp is fixed.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => {
	const plugin = () => ({})
	return {
		createObjectStore: () => () => ({}),
		auditTrailsPlugin: plugin,
		filesPlugin: plugin,
		lifecyclePlugin: plugin,
		liveUpdatesPlugin: plugin,
		relationsPlugin: plugin,
		selectionPlugin: plugin,
	}
})

import { useObjectStore } from '../../src/store/modules/object.js'

const NOW = '2026-01-02T03:04:05.000Z'

/**
 * A store whose collaborators record every call into `calls`.
 *
 * @param {Array} calls - The call log
 * @return {object} The store
 */
function recordingStore(calls) {
	const store = useObjectStore()
	vi.spyOn(store, 'setLoading').mockImplementation((key, value) => {
		calls.push(['setLoading', key, value])
	})
	vi.spyOn(store, 'setError').mockImplementation((key, value) => {
		calls.push(['setError', key, value])
	})
	vi.spyOn(store, 'setState').mockImplementation((key, value) => {
		calls.push(['setState', key, value])
	})
	vi.spyOn(store, 'fetchSettings').mockImplementation(async () => {
		calls.push(['fetchSettings'])
	})
	vi.spyOn(store, 'fetchCollection').mockImplementation(async (type) => {
		calls.push(['fetchCollection', type])
	})
	vi.spyOn(store, 'setActiveObject').mockImplementation((type, obj) => {
		calls.push(['setActiveObject', type, obj])
	})
	vi.spyOn(store, '_constructApiUrl').mockImplementation((...args) => {
		calls.push(['_constructApiUrl', ...args])
		return '/api/' + args[0] + (args[1] ? '/' + args[1] : '')
	})
	return store
}

/**
 * A fetch that records its call and answers with `answer`.
 *
 * @param {Array} calls - The call log
 * @param {object} answer - The response
 * @return {Function} The fetch
 */
function recordingFetch(calls, answer) {
	return vi.fn(async (url, init) => {
		calls.push(['fetch', url, init])
		if (answer instanceof Error) {
			throw answer
		}
		return answer
	})
}

const okAnswer = { ok: true, json: async () => ({ id: 'n1', title: 'x' }) }
const badAnswer = { ok: false, status: 409, statusText: 'Conflict' }

describe('object store write actions (characterisation)', () => {
	let calls

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers()
		vi.setSystemTime(new Date(NOW))
		vi.spyOn(console, 'error').mockImplementation(() => {})
		calls = []
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('saveObject requires an item, a register and a schema', async () => {
		const store = recordingStore(calls)
		await expect(store.saveObject(null, { register: 1, schema: 2 })).rejects.toThrow('Object item, register and schema are required')
		await expect(store.saveObject({}, { register: null, schema: 2 })).rejects.toThrow('Object item, register and schema are required')
		await expect(store.saveObject({}, { register: 1, schema: 0 })).rejects.toThrow('Object item, register and schema are required')
		expect(calls).toEqual([])
	})

	it('saveObject refuses an unextractable register or schema id', async () => {
		const store = recordingStore(calls)
		await expect(store.saveObject({}, { register: {}, schema: 2 })).rejects.toThrow('Could not extract register or schema ID')
	})

	it('saveObject POSTs a new object and stamps @self.updated', async () => {
		const store = recordingStore(calls)
		vi.stubGlobal('fetch', recordingFetch(calls, okAnswer))
		const item = { title: 'a' }
		const result = await store.saveObject(item, { register: { id: 7 }, schema: '9' })
		expect(result.data).toEqual({ id: 'n1', title: 'x' })
		expect(result.response).toBe(okAnswer)
		expect(calls).toEqual([
			['fetch', '/index.php/apps/openregister/api/objects/7/9', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ title: 'a', '@self': { updated: NOW } }),
			}],
		])
	})

	it('saveObject PUTs an existing object to its own URL', async () => {
		const store = recordingStore(calls)
		vi.stubGlobal('fetch', recordingFetch(calls, okAnswer))
		const item = { '@self': { id: 'o1' } }
		await store.saveObject(item, { register: '7', schema: { id: 9 } })
		expect(calls[0][1]).toBe('/index.php/apps/openregister/api/objects/7/9/o1')
		expect(calls[0][2].method).toBe('PUT')
		expect(item['@self'].updated).toBe(NOW)
	})

	it('saveObject throws the status on a failed answer and logs it', async () => {
		const store = recordingStore(calls)
		vi.stubGlobal('fetch', recordingFetch(calls, badAnswer))
		await expect(store.saveObject({}, { register: 1, schema: 2 })).rejects.toThrow('Failed to save object: 409 Conflict')
		expect(console.error).toHaveBeenCalledWith('Error saving object:', expect.any(Error))
	})

	it('createObject fetches settings, posts, stores, refreshes and activates', async () => {
		const store = recordingStore(calls)
		vi.stubGlobal('fetch', recordingFetch(calls, okAnswer))
		const pub = { register: 1, schema: 2 }
		const result = await store.createObject('catalog', { a: 1 }, pub)
		expect(result).toEqual({ id: 'n1', title: 'x' })
		expect(store.objects.catalog.n1).toEqual({ id: 'n1', title: 'x' })
		expect(calls).toEqual([
			['setLoading', 'catalog_create', true],
			['setError', 'catalog_create', null],
			['setState', 'catalog', { success: null, error: null }],
			['fetchSettings'],
			['_constructApiUrl', 'catalog', null, null, {}, pub],
			['fetch', '/api/catalog', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ a: 1 }),
			}],
			['fetchCollection', 'catalog'],
			['setActiveObject', 'catalog', { id: 'n1', title: 'x' }],
			['setState', 'catalog', { success: true, error: null }],
			['setLoading', 'catalog_create', false],
		])
	})

	it('createObject skips settings when loaded and the refresh for publications', async () => {
		const store = recordingStore(calls)
		store.settings = { loaded: true }
		vi.stubGlobal('fetch', recordingFetch(calls, okAnswer))
		await store.createObject('publication', {})
		const names = calls.map((c) => c[0])
		expect(names).not.toContain('fetchSettings')
		expect(names).not.toContain('fetchCollection')
		expect(calls).toContainEqual(['_constructApiUrl', 'publication', null, null, {}, null])
	})

	it('createObject records the failure and rethrows', async () => {
		const store = recordingStore(calls)
		store.settings = {}
		vi.stubGlobal('fetch', recordingFetch(calls, badAnswer))
		await expect(store.createObject('theme', {})).rejects.toThrow('Failed to create theme object')
		expect(calls.slice(-3)).toEqual([
			['setError', 'theme_create', 'Failed to create theme object'],
			['setState', 'theme', { success: false, error: 'Failed to create theme object' }],
			['setLoading', 'theme_create', false],
		])
		expect(console.error).toHaveBeenCalledWith('Error creating theme object:', expect.any(Error))
	})

	it('updateObject puts, stores, refreshes and updates the active object', async () => {
		const store = recordingStore(calls)
		store.activeObjects = { catalog: { id: 'c1' } }
		vi.stubGlobal('fetch', recordingFetch(calls, okAnswer))
		const result = await store.updateObject('catalog', 'c1', { a: 2 })
		expect(result).toEqual({ id: 'n1', title: 'x' })
		expect(store.objects.catalog.c1).toEqual({ id: 'n1', title: 'x' })
		expect(store.activeObjects.catalog).toEqual({ id: 'n1', title: 'x' })
		expect(calls).toEqual([
			['setLoading', 'catalog_c1', true],
			['setError', 'catalog_c1', null],
			['setState', 'catalog', { success: null, error: null }],
			['fetchSettings'],
			['_constructApiUrl', 'catalog', 'c1'],
			['fetch', '/api/catalog/c1', {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ a: 2 }),
			}],
			['fetchCollection', 'catalog'],
			['setState', 'catalog', { success: true, error: null }],
			['setLoading', 'catalog_c1', false],
		])
	})

	it('updateObject leaves another active object alone', async () => {
		const store = recordingStore(calls)
		store.settings = {}
		store.activeObjects = { catalog: { id: 'other' } }
		vi.stubGlobal('fetch', recordingFetch(calls, okAnswer))
		await store.updateObject('catalog', 'c1', {})
		expect(store.activeObjects.catalog).toEqual({ id: 'other' })
	})

	it('updateObject records a network failure and rethrows', async () => {
		const store = recordingStore(calls)
		store.settings = {}
		vi.stubGlobal('fetch', recordingFetch(calls, new Error('offline')))
		await expect(store.updateObject('theme', 't1', {})).rejects.toThrow('offline')
		expect(calls.slice(-3)).toEqual([
			['setError', 'theme_t1', 'offline'],
			['setState', 'theme', { success: false, error: 'offline' }],
			['setLoading', 'theme_t1', false],
		])
		expect(console.error).toHaveBeenCalledWith('Error updating theme object:', expect.any(Error))
	})
})
