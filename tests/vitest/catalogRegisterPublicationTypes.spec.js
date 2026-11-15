/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the catalog store's registerPublicationTypes:
 * which publications register an object type, with which schema and register
 * ids, and that a slug is registered once.
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const registered = []

vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		availableSchemas: [],
		registerObjectType: async (...args) => {
			registered.push(args)
		},
	},
	navigationStore: {},
	searchStore: {},
	catalogStore: {},
}))

import { useCatalogStore } from '../../src/store/modules/catalog.js'

describe('catalog store — registerPublicationTypes', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		registered.length = 0
	})

	it('resolves ids through the @self maps and registers each slug once', async () => {
		const store = useCatalogStore()
		await store.registerPublicationTypes({
			'@self': {
				schemas: { 9: { id: 9, slug: 'doc' }, 10: { id: 10, slug: 'note' } },
				registers: { 7: { id: 7 } },
			},
			results: [
				{ '@self': { schema: 9, register: 7 } },
				{ '@self': { schema: '9', register: '7' } },
				{ '@self': { schema: 10, register: 8 } },
			],
		})
		expect(registered).toEqual([
			['doc', 9, 7],
			['note', 10, 8],
		])
		expect([...store.registeredTypes]).toEqual(['doc', 'note'])
	})

	it('reads embedded objects and the older top-level shape', async () => {
		const store = useCatalogStore()
		await store.registerPublicationTypes({
			results: [
				{ schema: { id: 3, slug: 'old' }, register: { id: 4 } },
				{ '@self': { schema: { slug: 'noid' }, register: 'r' } },
			],
		})
		expect(registered).toEqual([
			['old', 3, 4],
			['noid', { slug: 'noid' }, 'r'],
		])
	})

	it('skips publications without a schema, register or slug', async () => {
		const store = useCatalogStore()
		store.registeredTypes.add('seen')
		await store.registerPublicationTypes({
			'@self': { schemas: { 1: { id: 1 } , 2: { id: 2, slug: 'seen' } } },
			results: [
				{ '@self': { schema: 1 } },
				{ '@self': { register: 1 } },
				{ '@self': { schema: 1, register: 1 } },
				{ '@self': { schema: 2, register: 1 } },
				{ '@self': { schema: 99, register: 1 } },
			],
		})
		expect(registered).toEqual([])
	})

	it('accepts a page without results', async () => {
		const store = useCatalogStore()
		await store.registerPublicationTypes({})
		expect(registered).toEqual([])
	})
})
