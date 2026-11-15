/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the object store's single-attachment actions
 * (publishAttachment, depublishAttachment) and the registry paths of
 * getSchemaConfig: the request each sends, the loading and error keys it
 * sets, what it returns or throws, and the refresh that follows.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-request-token' }))

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

const PUBLICATION = { id: 'p1', '@self': { register: { id: 7 }, schema: '9' } }

/**
 * A store with an active publication whose collaborators record into `calls`.
 *
 * @param {Array} calls - The call log
 * @return {object} The store
 */
function recordingStore(calls) {
	const store = useObjectStore()
	store.activeObjects = { publication: PUBLICATION }
	vi.spyOn(store, 'setLoading').mockImplementation((key, value) => {
		calls.push(['setLoading', key, value])
	})
	vi.spyOn(store, 'setError').mockImplementation((key, value) => {
		calls.push(['setError', key, value])
	})
	vi.spyOn(store, 'refreshActivePublicationFiles').mockImplementation(async () => {
		calls.push(['refresh'])
	})
	return store
}

describe.each([
	['publishAttachment', 'publish', 'publishing'],
	['depublishAttachment', 'depublish', 'depublishing'],
])('object store %s (characterisation)', (action, path, verb) => {
	let calls

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'error').mockImplementation(() => {})
		calls = []
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('posts to the file action and refreshes the files', async () => {
		const store = recordingStore(calls)
		vi.stubGlobal('fetch', vi.fn(async (url, init) => {
			calls.push(['fetch', url, init])
			return { ok: true }
		}))
		await expect(store[action](42)).resolves.toBe(true)
		expect(calls).toEqual([
			['setLoading', `${path}_file_42`, true],
			['setError', `${path}_file_42`, null],
			['fetch', `/index.php/apps/openregister/api/objects/7/9/p1/files/42/${path}`, { method: 'POST', headers: { requesttoken: 'test-request-token' } }],
			['refresh'],
			['setLoading', `${path}_file_42`, false],
		])
	})

	it('records a failed answer and rethrows', async () => {
		const store = recordingStore(calls)
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 403, statusText: 'Forbidden' })))
		const message = `Failed to ${path} file 42: 403 Forbidden`
		await expect(store[action](42)).rejects.toThrow(message)
		expect(calls).toEqual([
			['setLoading', `${path}_file_42`, true],
			['setError', `${path}_file_42`, null],
			['setError', `${path}_file_42`, message],
			['setLoading', `${path}_file_42`, false],
		])
		expect(console.error).toHaveBeenCalledWith(`Error ${verb} attachment:`, expect.any(Error))
	})

	it.each([
		[undefined],
		[{ '@self': { register: 1, schema: 2 } }],
		[{ id: 'p1', '@self': { schema: 2 } }],
		[{ id: 'p1', '@self': { register: 1 } }],
		[{ id: 'p1' }],
	])('refuses without a complete active publication (%j)', async (publication) => {
		const store = recordingStore(calls)
		store.activeObjects = { publication }
		await expect(store[action](42)).rejects.toThrow('Active publication is not set or missing register/schema')
		expect(calls).toEqual([])
	})
})

describe('object store getSchemaConfig registry paths (characterisation)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('answers a registered type from the registry', () => {
		const store = useObjectStore()
		store.objectTypeRegistry = { doc: { schema: 's', register: 'r', other: 1 } }
		expect(store.getSchemaConfig('doc')).toEqual({ source: 'openregister', schema: 's', register: 'r' })
	})

	it('registers from settings when the registry is empty, then answers', () => {
		const store = useObjectStore()
		store.settings = { configuration: {} }
		vi.spyOn(store, '_registerTypesFromSettings').mockImplementation(() => {
			store.objectTypeRegistry = { doc: { schema: 's2', register: 'r2' } }
		})
		expect(store.getSchemaConfig('doc')).toEqual({ source: 'openregister', schema: 's2', register: 'r2' })
	})

	it('falls back to the configuration when registering does not know the type', () => {
		const store = useObjectStore()
		store.settings = { configuration: { doc_source: 'x', doc_schema: 'y', doc_register: 'z' } }
		vi.spyOn(store, '_registerTypesFromSettings').mockImplementation(() => {})
		expect(store.getSchemaConfig('doc')).toEqual({ source: 'x', schema: 'y', register: 'z' })
	})

	it('throws without settings', () => {
		const store = useObjectStore()
		expect(() => store.getSchemaConfig('doc')).toThrow('Settings not loaded')
	})
})
