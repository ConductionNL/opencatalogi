/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for a catalog's scope (src/services/catalogScope.js): which ids
 * count, and the register × schema pairs the publications page lists.
 */

import { describe, expect, it } from 'vitest'
import {
	catalogScopePairs,
	normaliseIdList,
	pairKey,
} from '../../src/services/catalogScope.js'

describe('normaliseIdList', () => {
	it('accepts numbers and numeric strings', () => {
		expect(normaliseIdList([19, '173', ' 7 '])).toEqual([19, 173, 7])
	})

	it('drops slugs, free text and other junk', () => {
		expect(
			normaliseIdList([
				'publication',
				'Voorbeeld Registers 1',
				'',
				'1.5',
				'-3',
				'12abc',
				1.5,
				0,
				-4,
				null,
				undefined,
				{ id: 5 },
				[6],
				true,
				Number.NaN,
				42,
			]),
		).toEqual([42])
	})

	it('keeps each id once, in first-seen order', () => {
		expect(normaliseIdList(['19', 19, 20, '20', 19])).toEqual([19, 20])
	})

	it('reads a JSON-encoded list', () => {
		expect(normaliseIdList('[19, "173", "slug"]')).toEqual([19, 173])
	})

	it('answers an empty list for anything that is not a list', () => {
		expect(normaliseIdList(undefined)).toEqual([])
		expect(normaliseIdList(null)).toEqual([])
		expect(normaliseIdList('not json')).toEqual([])
		expect(normaliseIdList('{"a": 1}')).toEqual([])
		expect(normaliseIdList(19)).toEqual([])
		expect(normaliseIdList({ 0: 19 })).toEqual([])
	})
})

describe('catalogScopePairs', () => {
	it('pairs one register with one schema', () => {
		expect(catalogScopePairs({ registers: ['19'], schemas: [173] })).toEqual([
			{ register: 19, schema: 173, key: '19-173' },
		])
	})

	it('crosses every register with every schema, registers first', () => {
		expect(
			catalogScopePairs({ registers: [1, 2], schemas: ['10', '20'] }).map(
				(pair) => pair.key,
			),
		).toEqual(['1-10', '1-20', '2-10', '2-20'])
	})

	it('builds pairs from the numeric ids only, deduplicated', () => {
		expect(
			catalogScopePairs({
				registers: ['19', 19, 'publication'],
				schemas: ['184', 'publiccode', 184],
			}),
		).toEqual([{ register: 19, schema: 184, key: '19-184' }])
	})

	it('answers no pairs when either side has no numeric id', () => {
		expect(
			catalogScopePairs({ registers: ['stackiq'], schemas: ['module'] }),
		).toEqual([])
		expect(catalogScopePairs({ registers: [19], schemas: [] })).toEqual([])
		expect(catalogScopePairs({ registers: [], schemas: [173] })).toEqual([])
		expect(catalogScopePairs({})).toEqual([])
		expect(catalogScopePairs(null)).toEqual([])
	})
})

describe('pairKey', () => {
	it('spells a pair as register-schema', () => {
		expect(pairKey({ register: 19, schema: 173 })).toBe('19-173')
	})
})
