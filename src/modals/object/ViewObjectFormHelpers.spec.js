/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the form helpers of `ViewObject.vue`: the tag
 * options it takes from the upload dialog, adding a tag, dropping a
 * property, the stalled-selection notice, the schema defaults, the schema
 * options and the required-field checks. Each runs against a plain `this`,
 * so no component is mounted.
 */

import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))

jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		catalogs: [],
		tags: undefined,
		getCollection(type) {
			return type === 'tags' ? this.tags : { results: this.catalogs }
		},
	},
}))

const { objectStore } = jest.requireMock('../../store/store.js')

/**
 * A plain `this` with the given state and every method of the component.
 *
 * @param {object} state - The component state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = { formData: {}, currentObject: null, ...state }
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * Evaluate one computed property against a context.
 *
 * @param {string} name - The computed property
 * @param {object} ctx - The context
 * @return {*} Its value
 */
function computed(name, ctx) {
	for (const [other, fn] of Object.entries(ViewObject.computed)) {
		if (!(other in ctx)) {
			Object.defineProperty(ctx, other, { get: () => fn.call(ctx), configurable: true })
		}
	}
	return ViewObject.computed[name].call(ctx)
}

beforeEach(() => {
	jest.spyOn(console, 'info').mockImplementation(() => {})
	jest.spyOn(console, 'error').mockImplementation(() => {})
	objectStore.tags = undefined
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('ViewObject tags from the upload dialog', () => {
	it('takes the updated tags, creating the edit options when missing', () => {
		const tags = ['a', 'b']
		const ctx = vm({ labelOptionsEdit: null })
		ctx.onUploadFilesTagsUpdated({ tags, newTags: ['b'] })
		expect(ctx.labelOptionsEdit).toEqual({ inputLabel: 'Labels', multiple: true, options: ['a', 'b'] })
		expect(ctx.labelOptionsEdit.options).not.toBe(tags)
		expect(console.info).toHaveBeenCalledWith('>>> [VIEWOBJECT] RECEIVED TAGS-UPDATED FROM UPLOADFILES', { total: 2, newTags: ['b'] })
	})

	it('treats a payload without lists as empty and keeps existing options settings', () => {
		const ctx = vm({ labelOptionsEdit: { inputLabel: 'X', options: ['old'] } })
		ctx.onUploadFilesTagsUpdated(null)
		expect(ctx.labelOptionsEdit).toEqual({ inputLabel: 'X', options: [] })
		expect(console.info).toHaveBeenCalledWith(expect.any(String), { total: 0, newTags: [] })
	})

	it.each([
		[{ tags: ['p'] }, ['s'], ['p'], false],
		[{ tags: 'x' }, ['s'], ['s'], false],
		[null, ['s'], ['s'], false],
		[{}, undefined, 'unchanged', true],
	])('on close with %j and stored %j shows %j', (payload, stored, options, refetch) => {
		objectStore.tags = stored
		const ctx = vm({ labelOptionsEdit: null })
		ctx.getAllTags = jest.fn()
		ctx.onUploadFilesClosed(payload)
		if (options === 'unchanged') {
			expect(ctx.labelOptionsEdit).toBe(null)
		} else {
			expect(ctx.labelOptionsEdit).toEqual({ inputLabel: 'Labels', multiple: true, options })
		}
		expect(ctx.getAllTags).toHaveBeenCalledTimes(refetch ? 1 : 0)
	})

	it.each([
		[null, ['a'], ['a'], ['a'], ['a']],
		['', ['a'], ['a'], ['a'], ['a']],
		['b', ['a'], ['a', 'b'], ['a'], ['a', 'b']],
		['a', ['a'], ['a'], ['a'], ['a']],
		['b', 'x', ['b'], null, ['b']],
	])('adds tag %j', (tag, options, expectedOptions, edited, expectedEdited) => {
		const ctx = vm({ labelOptionsEdit: { options }, editedTags: edited })
		ctx.addNewTag(tag)
		expect(ctx.labelOptionsEdit.options).toEqual(expectedOptions)
		expect(ctx.editedTags).toEqual(expectedEdited)
	})
})

describe('ViewObject dropProperty', () => {
	it.each([
		['arr', { arr: [1] }, []],
		['obj', { obj: { a: 1 } }, {}],
		['str', { str: 'x' }, null],
	])('clears schema property %s', (key, formData, cleared) => {
		const ctx = vm({ formData, selectedProperty: key })
		ctx.getSchemaProperties = () => ({ arr: { type: 'array' }, obj: { type: 'object' }, str: { type: 'string' } })
		ctx.dropProperty(key)
		expect(ctx.formData[key]).toEqual(cleared)
		expect(ctx.selectedProperty).toBe(null)
	})

	it('removes an extra property, marking a persisted one for deletion', () => {
		const ctx = vm({ formData: { a: 1, b: 2 }, currentObject: { b: 'kept' }, selectedProperty: 'other' })
		ctx.getSchemaProperties = () => ({})
		ctx.dropProperty('a')
		expect(Object.hasOwn(ctx.formData, 'a')).toBe(false)
		ctx.dropProperty('b')
		expect(Object.hasOwn(ctx.formData, 'b')).toBe(true)
		expect(ctx.formData.b).toBeUndefined()
		expect(ctx.selectedProperty).toBe('other')
		const noObject = vm({ formData: { c: 1 }, currentObject: null })
		noObject.getSchemaProperties = () => ({})
		noObject.dropProperty('c')
		expect(Object.hasOwn(noObject.formData, 'c')).toBe(false)
	})
})

describe('ViewObject selectionStalled', () => {
	const base = {
		isLockedCatalog: false,
		catalogOptions: [1],
		isNewObject: true,
		hasSelectedSchema: false,
		allSelectionsComplete: false,
		selectedCatalog: null,
		selectedRegister: null,
		registerOptions: [1],
		schemaOptions: [1],
	}
	it.each([
		[{}, false],
		[{ isLockedCatalog: true, selectedCatalog: {}, registerOptions: [] }, false],
		[{ catalogOptions: [], selectedCatalog: {}, registerOptions: [] }, false],
		[{ isNewObject: false, selectedCatalog: {}, registerOptions: [] }, false],
		[{ hasSelectedSchema: true, selectedCatalog: {}, registerOptions: [] }, false],
		[{ allSelectionsComplete: true, selectedCatalog: {}, registerOptions: [] }, false],
		[{ selectedCatalog: {}, registerOptions: [] }, true],
		[{ selectedCatalog: {} }, false],
		[{ selectedRegister: {}, schemaOptions: [] }, true],
		[{ selectedRegister: {} }, false],
	])('with %j is %s', (state, stalled) => {
		expect(computed('selectionStalled', { ...base, ...state })).toBe(stalled)
	})
})

describe('ViewObject applySchemaDefaults', () => {
	it('fills defaults, then consts, for visible properties the form lacks', () => {
		const ctx = vm({ formData: { kept: 'k' } })
		ctx.applySchemaDefaults({
			properties: {
				kept: { default: 'd' },
				withDefault: { default: 0, const: 'c' },
				withConst: { const: false },
				hidden: { default: 'h', hideOnForm: true },
				none: null,
				plain: { type: 'string' },
			},
		})
		expect(ctx.formData).toEqual({ kept: 'k', withDefault: 0, withConst: false })
		const empty = vm()
		empty.applySchemaDefaults(undefined)
		expect(empty.formData).toEqual({})
	})
})

describe('ViewObject schemaOptions', () => {
	beforeEach(() => {
		objectStore.availableRegisters = [{ id: 1, schemas: [{ id: 10 }, { id: 11 }, { id: 12 }] }, { id: 2 }]
		objectStore.catalogs = [{ id: 'c1', schemas: ['10', '11', '13'] }, { id: 'c2' }]
		objectStore.availableSchemas = [
			{ id: 11, title: 'beta' },
			{ id: 10, title: 'Alpha' },
			{ id: 11, title: 'beta again' },
			{ id: 13, title: 'not in register' },
			{ id: 10, title: 'hidden', private: true },
		]
	})

	it('lists the readable schemas in both register and catalog, once each, sorted', () => {
		const ctx = { selectedRegister: { id: '1' }, selectedCatalog: { id: 'c1' }, hasSchemaReadRight: (s) => !s.private }
		expect(computed('schemaOptions', ctx)).toEqual([
			{ id: 10, label: 'Alpha' },
			{ id: 11, label: 'beta' },
		])
	})

	it.each([
		[{ selectedRegister: null, selectedCatalog: { id: 'c1' } }],
		[{ selectedRegister: { id: 1 }, selectedCatalog: null }],
		[{ selectedRegister: { id: 9 }, selectedCatalog: { id: 'c1' } }],
		[{ selectedRegister: { id: 1 }, selectedCatalog: { id: 'zz' } }],
		[{ selectedRegister: { id: 2 }, selectedCatalog: { id: 'c1' } }],
		[{ selectedRegister: { id: 1 }, selectedCatalog: { id: 'c2' } }],
	])('lists none for %j', (state) => {
		expect(computed('schemaOptions', { hasSchemaReadRight: () => true, ...state })).toEqual([])
	})
})

describe('ViewObject required fields', () => {
	it('collects required keys from the schema list and the property flags', () => {
		const ctx = {
			resolvedSchema: {
				required: ['a', '@self', 'id'],
				properties: { b: { required: true }, c: { required: 'yes' }, a: { required: true }, d: null },
			},
		}
		expect(computed('requiredPropertyKeys', ctx)).toEqual(['a', 'b'])
		expect(computed('requiredPropertyKeys', { resolvedSchema: null })).toEqual([])
		expect(computed('requiredPropertyKeys', { resolvedSchema: {} })).toEqual([])
	})

	it('reports the required keys without a value', () => {
		const ctx = vm({
			requiredPropertyKeys: ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
			formData: { a: '', b: [], c: [0], d: 0, e: null, f: {} },
			currentObject: { g: undefined },
		})
		expect(computed('missingRequiredKeys', ctx)).toEqual(['a', 'b', 'e', 'g'])
	})
})
