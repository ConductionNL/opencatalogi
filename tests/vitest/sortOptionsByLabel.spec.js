/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for sortOptionsByLabel, the shared sort behind the schema
 * dropdowns (ViewObject, ObjectModal, CatalogModal).
 */

import { describe, expect, it } from 'vitest'
import { sortOptionsByLabel } from '../../src/services/sortOptionsByLabel.js'

const labels = (options) => options.map((option) => option.label)

describe('sortOptionsByLabel', () => {
	it('sorts alphabetically on label', () => {
		const options = [
			{ id: 1, label: 'Vergaderstukken decentrale overheden' },
			{ id: 2, label: 'Subsidieverplichtingen' },
			{ id: 3, label: 'Overige besluiten' },
			{ id: 4, label: 'Organisatie en werkwijze' },
		]

		expect(labels(sortOptionsByLabel(options))).toEqual([
			'Organisatie en werkwijze',
			'Overige besluiten',
			'Subsidieverplichtingen',
			'Vergaderstukken decentrale overheden',
		])
	})

	it('ignores case', () => {
		const options = [
			{ label: 'Zeta' },
			{ label: 'beta' },
			{ label: 'alpha' },
			{ label: 'Gamma' },
		]

		// Code-point order would put the capitals first: Gamma, Zeta, alpha, beta.
		expect(labels(sortOptionsByLabel(options))).toEqual([
			'alpha',
			'beta',
			'Gamma',
			'Zeta',
		])
	})

	it('treats labels differing only in case as equal and keeps their order', () => {
		const options = [
			{ id: 1, label: 'Alpha' },
			{ id: 2, label: 'alpha' },
		]

		expect(sortOptionsByLabel(options).map((option) => option.id)).toEqual([
			1, 2,
		])
	})

	it('sorts numbers naturally', () => {
		const options = [
			{ label: 'Schema 10' },
			{ label: 'Schema 2' },
			{ label: 'Schema 1' },
		]

		expect(labels(sortOptionsByLabel(options))).toEqual([
			'Schema 1',
			'Schema 2',
			'Schema 10',
		])
	})

	it('does not mutate the input array', () => {
		const options = [{ label: 'b' }, { label: 'a' }]
		const snapshot = [...options]

		const sorted = sortOptionsByLabel(options)

		expect(sorted).not.toBe(options)
		expect(options).toEqual(snapshot)
		expect(options[0]).toBe(snapshot[0])
	})

	it('sorts a missing or empty label as an empty string without throwing', () => {
		const options = [
			{ id: 'b', label: 'Beta' },
			{ id: 'none' },
			{ id: 'null', label: null },
			{ id: 'empty', label: '' },
			{ id: 'a', label: 'Alpha' },
		]

		const sorted = sortOptionsByLabel(options)

		expect(
			sorted
				.slice(0, 3)
				.map((option) => option.id)
				.sort(),
		).toEqual(['empty', 'none', 'null'])
		expect(sorted.slice(3).map((option) => option.id)).toEqual(['a', 'b'])
	})

	it('returns an empty array for non-array input', () => {
		expect(sortOptionsByLabel(undefined)).toEqual([])
		expect(sortOptionsByLabel(null)).toEqual([])
	})
})
