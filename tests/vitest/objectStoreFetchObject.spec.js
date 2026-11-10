/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the object store's fetchObject: the loading and
 * state bookkeeping, the delegation to the inner store, the cache and active
 * object it updates, what it returns and the errors it throws.
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

/**
 * A store whose collaborators record into `calls`, with an inner store that
 * answers `answer` and reports `innerError`.
 *
 * @param {Array} calls - The call log
 * @param {{answer: *, innerError: *}} inner - The inner store's answer and error
 * @return {object} The store
 */
function recordingStore(calls, { answer, innerError }) {
	const store = useObjectStore()
	vi.spyOn(store, 'setLoading').mockImplementation((key, value) => calls.push(['setLoading', key, value]))
	vi.spyOn(store, 'setState').mockImplementation((key, value) => calls.push(['setState', key, value]))
	vi.spyOn(store, 'fetchSettings').mockImplementation(async () => calls.push(['fetchSettings']))
	vi.spyOn(store, '_ensureInnerType').mockImplementation((type) => calls.push(['ensure', type]))
	vi.spyOn(store, 'setActiveObject').mockImplementation(async (type, data) => calls.push(['setActiveObject', type, data]))
	vi.spyOn(store, '_inner').mockReturnValue({
		fetchObject: async (type, id) => {
			calls.push(['inner.fetchObject', type, id])
			if (answer instanceof Error) throw answer
			return answer
		},
		getError: (type) => {
			calls.push(['inner.getError', type])
			return innerError
		},
	})
	return store
}

describe('object store fetchObject (characterisation)', () => {
	let calls

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'error').mockImplementation(() => {})
		calls = []
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads settings, fetches through the inner store and caches the object', async () => {
		const store = recordingStore(calls, { answer: { id: '1', a: 1 } })
		const result = await store.fetchObject('theme', '1')
		expect(result).toEqual({ id: '1', a: 1 })
		expect(store.objects.theme['1']).toEqual({ id: '1', a: 1 })
		expect(calls).toEqual([
			['setLoading', 'theme_1', true],
			['setState', 'theme', { success: null, error: null }],
			['fetchSettings'],
			['ensure', 'theme'],
			['inner.fetchObject', 'theme', '1'],
			['setLoading', 'theme_1', false],
		])
	})

	it('refreshes the active object when it is the fetched one', async () => {
		const store = recordingStore(calls, { answer: { id: '1', b: 2 } })
		store.settings = {}
		store.objects = { theme: { other: {} } }
		store.activeObjects = { theme: { id: '1' } }
		await store.fetchObject('theme', '1')
		expect(calls).toContainEqual(['setActiveObject', 'theme', { id: '1', b: 2 }])
		expect(calls.map((c) => c[0])).not.toContain('fetchSettings')
		expect(store.objects.theme.other).toEqual({})
	})

	it.each([
		[{ message: 'inner says no' }, 'inner says no'],
		[null, 'Failed to fetch theme object'],
		[{}, 'Failed to fetch theme object'],
	])('throws the inner error %j for a missing object', async (innerError, message) => {
		const store = recordingStore(calls, { answer: null, innerError })
		store.settings = {}
		await expect(store.fetchObject('theme', '9')).rejects.toThrow(message)
		expect(calls.slice(-3)).toEqual([
			['inner.getError', 'theme'],
			['setState', 'theme', { success: false, error: message }],
			['setLoading', 'theme_9', false],
		])
		expect(console.error).toHaveBeenCalledWith('Error fetching theme object:', expect.any(Error))
	})

	it('records a failing inner fetch and rethrows', async () => {
		const store = recordingStore(calls, { answer: new Error('network') })
		store.settings = {}
		await expect(store.fetchObject('theme', '9')).rejects.toThrow('network')
		expect(calls.slice(-2)).toEqual([
			['setState', 'theme', { success: false, error: 'network' }],
			['setLoading', 'theme_9', false],
		])
	})
})
