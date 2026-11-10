/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for three small modal helpers: MergeObject's displayValue,
 * ObjectModal's schema options and AddDirectoryModal's date formatting.
 */

import AddDirectoryModal from '../directory/AddDirectoryModal.vue'
import MergeObject from './MergeObject.vue'
import ObjectModal from './ObjectModal.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	catalogStore: {},
	objectStore: { availableRegisters: [], availableSchemas: [], getCollection: jest.fn() },
}))

describe('MergeObject displayValue', () => {
	const show = (...args) => MergeObject.methods.displayValue(...args)

	it.each([
		[null, 'N/A'],
		[undefined, 'N/A'],
		[0, '0'],
		[false, 'false'],
		['short', 'short'],
		[{ a: 1 }, '{\n  "a": 1\n}'],
	])('shows %j as %j', (value, expected) => {
		expect(show(value)).toBe(expected)
	})

	it('cuts long text at 100 characters by default', () => {
		expect(show('x'.repeat(101))).toBe('x'.repeat(100) + '...')
		expect(show('x'.repeat(100))).toBe('x'.repeat(100))
	})

	it('cuts at the given length', () => {
		expect(show('abcdef', 3)).toBe('abc...')
		expect(show([1, 2], 4)).toBe('[\n  ...')
	})
})

describe('ObjectModal schemaOptions', () => {
	const options = (state) => ObjectModal.computed.schemaOptions.call(state)

	beforeEach(() => {
		objectStore.availableRegisters = [{ id: 1, schemas: [{ id: 10 }, { id: 11 }, { id: 12 }] }]
		objectStore.availableSchemas = [
			{ id: 12, title: 'beta' },
			{ id: 10, title: 'Alpha' },
			{ id: 11, title: 'Gamma' },
			{ id: 13, title: 'Delta' },
		]
		objectStore.getCollection.mockReturnValue({ results: [{ id: 'c1', schemas: [10, 12, 13] }] })
	})

	it.each([
		['no register', { selectedRegister: null, selectedCatalogus: { id: 'c1' } }],
		['no catalog', { selectedRegister: { id: 1 }, selectedCatalogus: null }],
	])('is empty with %s', (label, state) => {
		expect(options(state)).toEqual([])
	})

	it('offers the schemas in both the register and the catalog, sorted on label', () => {
		expect(options({ selectedRegister: { id: 1 }, selectedCatalogus: { id: 'c1' } })).toEqual([
			{ id: 10, label: 'Alpha' },
			{ id: 12, label: 'beta' },
		])
		expect(objectStore.getCollection).toHaveBeenCalledWith('catalog')
	})

	it('throws for a register the store does not know', () => {
		expect(() => options({ selectedRegister: { id: 9 }, selectedCatalogus: { id: 'c1' } })).toThrow(TypeError)
	})
})

describe('AddDirectoryModal formatDateTime', () => {
	const format = (value) => AddDirectoryModal.methods.formatDateTime(value)

	it.each([[null], [''], ['not a date']])('says Unknown for %j', (value) => {
		expect(format(value)).toBe('Unknown')
	})

	it('formats a date in Dutch with seconds', () => {
		const at = '2026-01-02T03:04:05Z'
		expect(format(at)).toBe(
			new Date(at).toLocaleString('nl-NL', {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit',
			}),
		)
	})

	it('says Unknown when formatting throws', () => {
		const spy = jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(() => {
			throw new RangeError('bad')
		})
		expect(format('2026-01-02T03:04:05Z')).toBe('Unknown')
		spy.mockRestore()
	})
})
