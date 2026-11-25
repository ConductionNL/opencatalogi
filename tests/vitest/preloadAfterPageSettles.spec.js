/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * App boot loads the catalog collection at once (it fills the navigation's
 * catalog entries) and the other collections only after the page has
 * settled: on the reference stack they were nine list requests on every
 * page, ahead of the page's own reads.
 *
 * @spec openspec/specs/generic-object-modals/spec.md
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { afterPageSettles } from '../../src/services/afterPageSettles.js'

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

function storeWithTypes(types) {
	const store = useObjectStore()
	vi.spyOn(store, '_ensureInnerType').mockImplementation(() => {})
	vi.spyOn(store, 'fetchSettings').mockImplementation(async () => {
		store.settings = { objectTypes: types, configuration: {} }
	})
	return store
}

describe('preloadCollections with later', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'warn').mockImplementation(() => {})
	})
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the catalog collection first and the others only after later() resolves', async () => {
		const store = storeWithTypes(['theme', 'catalog', 'page'])
		const fetchCollection = vi.spyOn(store, 'fetchCollection').mockResolvedValue()
		let release
		const later = vi.fn(() => new Promise((resolve) => { release = resolve }))

		const done = store.preloadCollections({ later })
		await vi.waitFor(() => expect(later).toHaveBeenCalled())

		expect(fetchCollection.mock.calls.map((c) => c[0])).toEqual(['catalog'])
		release()
		await done
		expect(fetchCollection.mock.calls.map((c) => c[0])).toEqual(['catalog', 'theme', 'page'])
	})

	it('without later, loads every type at once as before', async () => {
		const store = storeWithTypes(['theme', 'catalog'])
		const fetchCollection = vi.spyOn(store, 'fetchCollection').mockResolvedValue()

		await store.preloadCollections()

		expect(fetchCollection.mock.calls.map((c) => c[0])).toEqual(['theme', 'catalog'])
	})

	it('App.vue boots with afterPageSettles', () => {
		const source = readFileSync(path.resolve(__dirname, '../../src/App.vue'), 'utf8')
		expect(source).toMatch(/objectStore\.preloadCollections\(\{ later: afterPageSettles \}\)/)
	})
})

describe('afterPageSettles', () => {
	afterEach(() => {
		vi.useRealTimers()
		delete window.requestIdleCallback
	})

	it('waits the delay, then an idle moment', async () => {
		vi.useFakeTimers()
		const idle = vi.fn((cb) => cb())
		window.requestIdleCallback = idle
		let settled = false
		afterPageSettles(2000).then(() => { settled = true })

		await vi.advanceTimersByTimeAsync(1999)
		expect(settled).toBe(false)
		expect(idle).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		expect(idle).toHaveBeenCalledWith(expect.any(Function), { timeout: 3000 })
		expect(settled).toBe(true)
	})

	it('resolves after the delay where the browser has no idle callback', async () => {
		vi.useFakeTimers()
		delete window.requestIdleCallback
		let settled = false
		afterPageSettles(10).then(() => { settled = true })
		await vi.advanceTimersByTimeAsync(10)
		expect(settled).toBe(true)
	})
})
