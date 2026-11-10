/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `ObjectModal.vue`'s initializeData: the catalog
 * it selects, whether it opens a new or an existing object, the form it
 * loads for an existing one and the register and schema it preselects. The
 * method runs against a plain `this`, so no component is mounted.
 */

import ObjectModal from './ObjectModal.vue'

jest.mock('@codemirror/lang-json', () => ({
	json: () => ({}),
	jsonParseLinter: () => () => [],
}))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))

jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: { getTransferData: jest.fn() },
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		active: {},
		catalogs: [],
		getActiveObject(type) {
			return this.active[type]
		},
		setActiveObject: jest.fn(function(type, value) {
			this.active[type] = value
		}),
		getCollection() {
			return { results: this.catalogs }
		},
	},
}))

const { navigationStore, objectStore } = jest.requireMock('../../store/store.js')

// jsdom has no structuredClone; the browser's copies plain data the same way.
if (typeof global.structuredClone !== 'function') {
	global.structuredClone = (value) => JSON.parse(JSON.stringify(value))
}

/**
 * Run initializeData on a fresh context.
 *
 * @return {object} The context afterwards
 */
function initialize() {
	const ctx = {
		selectedCatalogus: 'old',
		selectedRegister: 'old',
		selectedSchema: 'old',
		formData: 'old',
		jsonData: 'old',
		isNewObject: 'old',
	}
	ObjectModal.methods.initializeData.call(ctx)
	return ctx
}

beforeEach(() => {
	objectStore.availableRegisters = [{ id: 1, title: 'R1' }, { id: 2, title: 'R2' }]
	objectStore.availableSchemas = [{ id: 10, title: 'S10' }, { id: 11, title: 'S11' }]
	objectStore.active = {}
	objectStore.catalogs = []
	objectStore.setActiveObject.mockClear()
	navigationStore.getTransferData.mockReset().mockReturnValue(null)
})

describe('ObjectModal initializeData — catalog', () => {
	it('selects the listed active catalog', () => {
		objectStore.catalogs = [{ id: 'c1', title: 'One' }, { id: 'c2', title: 'Two' }]
		objectStore.active.catalog = { id: 'c2' }
		expect(initialize().selectedCatalogus).toEqual({ id: 'c2', label: 'Two' })
		expect(objectStore.setActiveObject).not.toHaveBeenCalled()
	})

	it('selects and activates the only catalog when none matches', () => {
		const only = { id: 'c1', title: 'One', registers: [2], schemas: [11] }
		objectStore.catalogs = [only]
		objectStore.active.catalog = { id: 'gone' }
		const ctx = initialize()
		expect(ctx.selectedCatalogus).toEqual({ id: 'c1', label: 'One' })
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('catalog', only)
		expect(ctx.selectedRegister).toEqual({ id: 2, label: 'R2' })
		expect(ctx.selectedSchema).toEqual({ id: 11, label: 'S11' })
	})

	it('selects nothing among several catalogs without an active one', () => {
		objectStore.catalogs = [{ id: 'c1' }, { id: 'c2' }]
		const ctx = initialize()
		expect(ctx.selectedCatalogus).toBe(null)
		expect(ctx.selectedRegister).toBe('old')
	})
})

describe('ObjectModal initializeData — new or existing', () => {
	it.each([
		['create', { id: 'p1', '@self': { id: 'p1', register: '1', schema: '10' } }, true],
		['ignore selectedCatalogus', { id: 'p1', '@self': { id: 'p1', register: '1', schema: '10' } }, true],
		[null, null, true],
		[null, undefined, true],
		[null, {}, true],
		[null, { title: 'no ids', '@self': {} }, true],
		[null, { id: 'p1', '@self': { register: '1', schema: '10' } }, false],
		[null, { '@self': { id: 'p1', register: 2, schema: 11 } }, false],
	])('with transfer %s and object %j is new: %s', (transfer, publication, isNew) => {
		navigationStore.getTransferData.mockReturnValue(transfer)
		objectStore.active.publication = publication
		expect(initialize().isNewObject).toBe(isNew)
	})

	it('loads an existing object into the form with its register and schema', () => {
		const publication = { id: 'p1', title: 'T', '@self': { id: 'p1', register: '1', schema: 10 } }
		objectStore.active.publication = publication
		const ctx = initialize()
		expect(ctx.formData).toEqual(publication)
		expect(ctx.formData).not.toBe(publication)
		expect(ctx.jsonData).toBe(JSON.stringify(publication, null, 2))
		expect(ctx.selectedRegister).toEqual({ id: 1, label: 'R1' })
		expect(ctx.selectedSchema).toEqual({ id: 10, label: 'S10' })
	})

	it('leaves unknown register and schema of an existing object undefined', () => {
		objectStore.active.publication = { id: 'p1', '@self': { register: 9, schema: 99 } }
		const ctx = initialize()
		expect(ctx.selectedRegister).toBeUndefined()
		expect(ctx.selectedSchema).toBeUndefined()
	})
})

describe('ObjectModal initializeData — preselection for a new object', () => {
	it.each([
		[{ id: 'c', registers: [1], schemas: [10] }, { id: 1, label: 'R1' }, { id: 10, label: 'S10' }],
		[{ id: 'c', registers: [1], schemas: [10, 11] }, { id: 1, label: 'R1' }, 'old'],
		[{ id: 'c', registers: [1] }, { id: 1, label: 'R1' }, 'old'],
		[{ id: 'c', registers: [7], schemas: [77] }, undefined, undefined],
		[{ id: 'c', registers: [1, 2], schemas: [10] }, 'old', 'old'],
		[{ id: 'c' }, 'old', 'old'],
		[undefined, 'old', 'old'],
	])('from catalog %j', (catalog, register, schema) => {
		navigationStore.getTransferData.mockReturnValue('create')
		objectStore.active.catalog = catalog
		objectStore.catalogs = catalog ? [catalog, { id: 'other' }] : []
		const ctx = initialize()
		expect(ctx.selectedRegister).toEqual(register)
		expect(ctx.selectedSchema).toEqual(schema)
		expect(ctx.formData).toBe('old')
	})
})
