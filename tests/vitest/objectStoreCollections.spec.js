/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the object store's setCollection (replace or
 * append, and the per-id mirror) and _ensureInnerType (when and with which
 * register and schema it registers a type in the inner store).
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

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

describe('object store setCollection (characterisation)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('replaces the collection and mirrors items by id or @self id', () => {
		const store = useObjectStore()
		store.objects = { doc: { old: { id: 'old' } } }
		const a = { id: 'a', v: 1 }
		store.setCollection('doc', [a, { '@self': { id: 'b' } }, { title: 'no id' }, null])
		expect(store.collections.doc).toEqual({ results: [a, { '@self': { id: 'b' } }, { title: 'no id' }, null] })
		expect(store.objects.doc).toEqual({ old: { id: 'old' }, a: { id: 'a', v: 1 }, b: { '@self': { id: 'b' } } })
		expect(store.objects.doc.a).not.toBe(a)
	})

	it('appends to an existing collection and starts a missing one', () => {
		const store = useObjectStore()
		store.setCollection('doc', [{ id: 1 }])
		store.setCollection('doc', [{ id: 2 }], true)
		expect(store.collections.doc.results).toEqual([{ id: 1 }, { id: 2 }])
		store.setCollection('fresh', [{ id: 3 }], true)
		expect(store.collections.fresh.results).toEqual([{ id: 3 }])
		store.setCollection('doc', [{ id: 4 }])
		expect(store.collections.doc.results).toEqual([{ id: 4 }])
	})
})

describe('object store _ensureInnerType (characterisation)', () => {
	let registered
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		registered = []
		store = useObjectStore()
	})

	/**
	 * Give the store an inner store with the given registry.
	 *
	 * @param {object} registry - The inner registry
	 */
	function inner(registry) {
		vi.spyOn(store, '_inner').mockReturnValue({
			objectTypeRegistry: registry,
			registerObjectType: (...args) => registered.push(args),
		})
	}

	it('leaves a type the inner store knows', () => {
		inner({ doc: {} })
		store.objectTypeRegistry = { doc: { schema: 1, register: 2 } }
		store._ensureInnerType('doc')
		expect(registered).toEqual([])
	})

	it('registers from the outer registry as text', () => {
		inner(undefined)
		store.objectTypeRegistry = { doc: { schema: 1, register: 2 } }
		store._ensureInnerType('doc')
		expect(registered).toEqual([['doc', '1', '2']])
	})

	it('falls back to the schema config, and skips when that fails or is incomplete', () => {
		inner({})
		store.objectTypeRegistry = { doc: { schema: 1 } }
		const config = vi.spyOn(store, 'getSchemaConfig').mockReturnValue({ schema: 's', register: 'r' })
		store._ensureInnerType('doc')
		expect(registered).toEqual([['doc', 's', 'r']])

		config.mockReturnValue({ schema: 's' })
		store._ensureInnerType('other')
		config.mockImplementation(() => {
			throw new Error('Settings not loaded')
		})
		store._ensureInnerType('third')
		expect(registered).toEqual([['doc', 's', 'r']])
	})
})
