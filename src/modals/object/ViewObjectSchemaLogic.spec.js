/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the schema, metadata and payload logic of
 * `ViewObject.vue`: the metadata rows, the modal title, the schema the
 * object resolves to, its schema properties, the field errors, the property
 * drop guard, the publish-action guard and the payload built for a save.
 * Each computed property and method runs against a plain `this`, so no
 * component is mounted.
 */

import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))

jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('@conduction/nextcloud-vue'),
	validateValue: (value, prop) => (value === 'bad' ? `bad ${prop.type}` : null),
}))

jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: { availableRegisters: [], availableSchemas: [] },
}))

const { objectStore } = jest.requireMock('../../store/store.js')

/**
 * A plain `this` with the given state and every method of the component.
 *
 * @param {object} state - The component state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = { formData: {}, currentObject: null, isNewObject: false, selectedSchema: null, ...state }
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
	return ViewObject.computed[name].call(ctx)
}

const local = (v) => new Date(v).toLocaleString()

beforeEach(() => {
	objectStore.availableRegisters = [{ id: 7, title: 'Reg seven' }, { id: 8 }]
	objectStore.availableSchemas = [
		{ id: 1, uuid: 'u-1', slug: 'doc', title: 'Document', properties: { title: { type: 'string' } } },
		{ id: 2, uuid: 'u-2', slug: 'note', name: 'Note name', properties: { body: { type: 'string' } } },
		{ id: 3, slug: 'bare' },
	]
})

describe('ViewObject metadataExtraRows', () => {
	it('is empty without an object', () => {
		expect(computed('metadataExtraRows', vm())).toEqual([])
	})

	it('falls back to the not-set labels', () => {
		expect(computed('metadataExtraRows', vm({ currentObject: {} }))).toEqual([
			['ID', 'Not set'],
			['Version', 'Not set'],
			['Register', 'Not set'],
			['Schema', 'Not set'],
			['Locked', 'Not locked'],
			['Created', 'Not set'],
			['Updated', 'Not set'],
			['Published', 'Not published'],
			['Depublished', 'Not depublished'],
		])
	})

	it('describes listed ids, embedded objects, a full lock and the dates', () => {
		const currentObject = {
			id: 'o1',
			'@self': {
				version: '1.2',
				register: 7,
				schema: { name: 'Embedded' },
				locked: { lockedBy: 'ann', lockedAt: '2026-01-02T03:04:05Z', process: 'review' },
				created: '2026-01-01T00:00:00Z',
				updated: '2026-01-03T00:00:00Z',
				published: '2026-01-04T00:00:00Z',
				depublished: '2026-01-05T00:00:00Z',
			},
		}
		expect(computed('metadataExtraRows', vm({ currentObject }))).toEqual([
			['ID', 'o1'],
			['Version', '1.2'],
			['Register', 'Reg seven'],
			['Schema', 'Embedded'],
			['Locked', `Locked by ann at ${local('2026-01-02T03:04:05Z')} (review)`],
			['Created', local('2026-01-01T00:00:00Z')],
			['Updated', local('2026-01-03T00:00:00Z')],
			['Published', local('2026-01-04T00:00:00Z')],
			['Depublished', local('2026-01-05T00:00:00Z')],
		])
	})

	it.each([
		[{ register: 8, schema: 99 }, 8, 99],
		[{ register: { title: 'T', name: 'N' }, schema: { id: 5 } }, 'T', 5],
		[{ register: { name: 'N' }, schema: 1 }, 'N', 'Document'],
		[{ register: { other: 1 }, schema: '1' }, { other: 1 }, '1'],
	])('shows register and schema of %j', (self, register, schema) => {
		const rows = computed('metadataExtraRows', vm({ currentObject: { '@self': self } }))
		expect(rows[2]).toEqual(['Register', register])
		expect(rows[3]).toEqual(['Schema', schema])
	})

	it.each([
		[true, 'Locked'],
		['yes', 'Locked'],
		[{}, 'Locked by Unknown user at Unknown time'],
	])('shows lock %j as %s', (locked, label) => {
		const rows = computed('metadataExtraRows', vm({ currentObject: { '@self': { locked } } }))
		expect(rows[4]).toEqual(['Locked', label])
	})
})

describe('ViewObject getModalTitle', () => {
	it('names the create and empty states', () => {
		expect(vm({ isNewObject: true }).getModalTitle()).toBe('Create Publication')
		expect(vm().getModalTitle()).toBe('View Object')
	})

	it.each([
		[{ '@self': { name: 'Self' }, name: 'n', schema: { title: 'ST' } }, 'Self (ST)'],
		[{ name: 'n', schema: { name: 'SN' } }, 'n (SN)'],
		[{ title: 'tt', schema: { id: 4 } }, 'tt (4)'],
		[{ id: 'i1', schema: {} }, 'i1 (Publication)'],
		[{ schema: '2' }, 'Untitled (Note name)'],
		[{ '@self': { schema: 1 } }, 'Untitled (Document)'],
		[{ schema: 3 }, 'Untitled (3)'],
		[{ schema: 'x' }, 'Untitled (x)'],
		[{ schema: '' }, 'Untitled (Publication)'],
		[{ schema: null, '@self': { schema: 1 } }, 'Untitled (Document)'],
		[{}, 'Untitled (Publication)'],
	])('titles %j as %s', (currentObject, title) => {
		expect(vm({ currentObject }).getModalTitle()).toBe(title)
	})
})

describe('ViewObject resolvedSchema', () => {
	it('uses the selected schema of a new object', () => {
		expect(computed('resolvedSchema', vm({ isNewObject: true, selectedSchema: { id: 2 } }))).toBe(objectStore.availableSchemas[1])
		expect(computed('resolvedSchema', vm({ isNewObject: true, selectedSchema: { id: '2' } }))).toBe(null)
	})

	it.each([
		[null, null],
		[{}, null],
		[{ '@self': {} }, null],
		[{ '@self': { schema: '' } }, null],
		[{ '@self': { schema: '1' } }, 0],
		[{ '@self': { schema: 'u-2' } }, 1],
		[{ '@self': { schema: 'bare' } }, 2],
		[{ '@self': { schema: { uuid: 'u-1' } } }, 0],
		[{ '@self': { schema: { slug: 'note' } } }, 1],
		[{ '@self': { schema: { other: 1 } } }, null],
		[{ '@self': { schema: 'missing' } }, null],
	])('resolves %j', (currentObject, index) => {
		const expected = index === null ? null : objectStore.availableSchemas[index]
		expect(computed('resolvedSchema', vm({ currentObject }))).toBe(expected)
	})

	it('prefers an embedded schema with properties', () => {
		const schema = { id: 1, properties: { a: {} } }
		expect(computed('resolvedSchema', vm({ currentObject: { '@self': { schema } } }))).toBe(schema)
	})
})

describe('ViewObject getSchemaProperties', () => {
	it('uses the selected schema of a new object', () => {
		expect(vm({ isNewObject: true, selectedSchema: { id: '1' } }).getSchemaProperties()).toEqual({ title: { type: 'string' } })
		expect(vm({ isNewObject: true, selectedSchema: { id: 3 } }).getSchemaProperties()).toEqual({})
		expect(vm({ isNewObject: true, selectedSchema: { id: 9 } }).getSchemaProperties()).toEqual({})
	})

	it.each([
		[null, {}],
		[{ '@self': {} }, {}],
		[{ '@self': { schema: { properties: { p: 1 } } } }, { p: 1 }],
		[{ '@self': { schema: { id: 2 } } }, { body: { type: 'string' } }],
		[{ '@self': { schema: { uuid: 'u-1' } } }, { title: { type: 'string' } }],
		[{ '@self': { schema: { slug: 'doc' } } }, {}],
		[{ '@self': { schema: 'doc' } }, { title: { type: 'string' } }],
		[{ '@self': { schema: 'bare' } }, {}],
		[{ '@self': { schema: 'missing' } }, {}],
	])('reads %j', (currentObject, properties) => {
		expect(vm({ currentObject }).getSchemaProperties()).toEqual(properties)
	})

	it('falls back to the current object for a new object without a selection', () => {
		const ctx = vm({ isNewObject: true, currentObject: { '@self': { schema: 2 } } })
		expect(ctx.getSchemaProperties()).toEqual({ body: { type: 'string' } })
	})
})

describe('ViewObject fieldErrors and canDropProperty', () => {
	const properties = {
		'@self': { type: 'string', maxLength: 1 },
		id: { type: 'string', maxLength: 1 },
		hidden: { type: 'string', maxLength: 1, hideOnForm: true },
		none: null,
		short: { type: 'string', maxLength: 2, title: 'Short' },
		count: { type: 'integer' },
		empty: { type: 'string', maxLength: 1 },
	}

	it('validates the visible, filled properties', () => {
		const ctx = vm({
			resolvedSchema: { properties },
			formData: { '@self': 'bad', id: 'bad', hidden: 'bad', short: 'bad', count: 'bad', empty: '' },
			currentObject: { empty: 'bad' },
		})
		expect(computed('fieldErrors', ctx)).toEqual([
			{ key: 'short', label: 'Short', error: 'bad string' },
			{ key: 'count', label: 'count', error: 'bad integer' },
		])
	})

	it('validates the persisted value when the form has none', () => {
		const ctx = vm({
			resolvedSchema: { properties: { a: { type: 'string' }, b: { type: 'string' } } },
			formData: {},
			currentObject: { a: 'bad', b: 'good' },
		})
		expect(computed('fieldErrors', ctx)).toEqual([{ key: 'a', label: 'a', error: 'bad string' }])
	})

	it('has no errors without a schema or properties', () => {
		expect(computed('fieldErrors', vm({ resolvedSchema: null }))).toEqual([])
		expect(computed('fieldErrors', vm({ resolvedSchema: {} }))).toEqual([])
	})

	it.each([
		['@self', 'x', false],
		['id', 'x', false],
		['locked', 'x', false],
		['a', null, false],
		['a', undefined, false],
		['a', '', false],
		['a', [], false],
		['a', {}, false],
		['a', [1], true],
		['a', { b: 1 }, true],
		['a', 0, true],
		['a', 'x', true],
	])('drop guard for %s = %j is %s', (key, value, expected) => {
		const ctx = vm({ formData: { [key]: value }, currentObject: {} })
		ctx.isConstantOrImmutable = (k) => k === 'locked'
		expect(ctx.canDropProperty(key, value)).toBe(expected)
	})
})

describe('ViewObject shouldShowPublishAction', () => {
	beforeEach(() => {
		jest.useFakeTimers().setSystemTime(new Date('2026-06-01T00:00:00Z'))
	})
	afterEach(() => {
		jest.useRealTimers()
	})

	it.each([
		[null, false],
		[{}, true],
		[{ depublicationDate: '2026-05-01' }, true],
		[{ depublicationDate: '2026-07-01' }, false],
		[{ publicationDate: '2026-05-01', depublicationDate: '2026-07-01' }, false],
		[{ publicationDate: '2026-05-01', depublicationDate: '2026-05-15' }, true],
		[{ publicationDate: '2026-07-01' }, true],
		[{ publicationDate: '2026-05-01' }, false],
		[{ publicationDate: 'nonsense' }, false],
	])('for %j is %s', (object, expected) => {
		expect(vm().shouldShowPublishAction(object)).toBe(expected)
	})
})

describe('ViewObject payload building', () => {
	it('cleans the form data keys', () => {
		expect(vm({ formData: ['a'] }).cleanFormData()).toEqual({})
		expect(vm({ formData: null }).cleanFormData()).toEqual({})
		expect(vm({ formData: { ok: 1, 12: 2, _x: 3, $y: 4, '': 5, 'a1': 6 } }).cleanFormData()).toEqual({ ok: 1, a1: 6 })
	})

	it('adds schema defaults when rebuilding after an API result', () => {
		const ctx = vm({ currentObject: { keep: 1, '@self': { a: 1, b: 1 } } })
		ctx.getSchemaProperties = () => ({
			keep: { type: 'string' },
			s: { type: 'string' },
			c: { type: 'string', const: 'C' },
			n: { type: 'number' },
			i: { type: 'integer' },
			b: { type: 'boolean' },
			arr: { type: 'array' },
			obj: { type: 'object' },
			other: { type: 'file' },
			untyped: {},
			constructor: { type: 'constructor' },
		})
		expect(ctx.rebuildObjectWithSchemaProperties({ id: 'x', '@self': { b: 2 } })).toEqual({
			keep: 1,
			id: 'x',
			'@self': { a: 1, b: 2 },
			s: '',
			c: 'C',
			n: 0,
			i: 0,
			b: false,
			arr: [],
			obj: {},
			other: '',
			untyped: '',
			constructor: '',
		})
	})

	it('builds the complete object data from the object, the schema and the form', () => {
		const ctx = vm({
			currentObject: { id: 'o1', '@self': { id: 'o1' }, kept: 'k', gone: 'g', extra: 'e' },
			formData: {
				gone: undefined,
				arrStr: 'a, b ,,c',
				arrArr: ['x'],
				arrNull: null,
				arrFalse: false,
				arrNum: 5,
				arrZero: 0,
				plain: 'p',
				notInSchema: 'n',
				dropped: undefined,
				7: 'digits',
				_private: 'hidden',
			},
		})
		ctx.getSchemaProperties = () => ({
			kept: { type: 'string' },
			gone: { type: 'string' },
			arrStr: { type: 'array' },
			arrArr: { type: 'array' },
			arrNull: { type: 'array' },
			arrFalse: { type: 'array' },
			arrNum: { type: 'array' },
			arrZero: { type: 'array' },
			plain: { type: 'string' },
			missingStr: { type: 'string' },
			missingConst: { type: 'string', const: 'K' },
			missingNum: { type: 'number' },
			missingArr: { type: 'array' },
			missingOther: {},
		})
		const data = ctx.buildCompleteObjectData()
		expect(data).toEqual({
			'@self': { id: 'o1' },
			kept: 'k',
			extra: 'e',
			arrStr: ['a', 'b', 'c'],
			arrArr: ['x'],
			arrNull: null,
			arrFalse: [],
			arrNum: ['5'],
			arrZero: ['0'],
			plain: 'p',
			missingStr: null,
			missingConst: 'K',
			missingNum: null,
			missingArr: null,
			missingOther: null,
			notInSchema: 'n',
		})
		expect(Object.keys(data)).toEqual([
			'@self', 'kept', 'extra', 'arrStr', 'arrArr', 'arrNull', 'arrFalse', 'arrNum', 'arrZero', 'plain',
			'missingStr', 'missingConst', 'missingNum', 'missingArr', 'missingOther', 'notInSchema',
		])
	})

	it('builds from the form alone without a current object', () => {
		const ctx = vm({ currentObject: null, formData: { a: 1 } })
		ctx.getSchemaProperties = () => ({})
		expect(ctx.buildCompleteObjectData()).toEqual({ a: 1 })
	})
})
