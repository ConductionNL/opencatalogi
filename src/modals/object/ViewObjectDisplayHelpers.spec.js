/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the display helpers of `ViewObject.vue`: the
 * constant/immutable check, the property display name, the markdown editor
 * content and the depublish-action guard. Each runs against a plain
 * `this`, so no component is mounted.
 */

import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))

jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: { availableRegisters: [], availableSchemas: [] },
}))

/**
 * A plain `this` whose schema has the given properties.
 *
 * @param {object} properties - The schema properties
 * @return {object} The context
 */
function vm(properties = {}) {
	const ctx = {}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.getSchemaProperties = () => properties
	return ctx
}

afterEach(() => {
	jest.useRealTimers()
	jest.restoreAllMocks()
})

describe('ViewObject isConstantOrImmutable', () => {
	const properties = {
		c: { const: 'x' },
		r: { readOnly: true },
		i: { immutable: true },
		n: { readOnly: 'yes', immutable: false },
		readonlyNote: { type: 'string' },
	}
	it.each([
		['c', true],
		['r', true],
		['i', true],
		['n', false],
		['readonlyNote', true],
		['MyImmutableField', true],
		['someConstantValue', true],
		['plain', false],
	])('%s is %s', (key, expected) => {
		expect(vm(properties).isConstantOrImmutable(key)).toBe(expected)
	})

	it('logs the detection in development only', () => {
		const log = jest.spyOn(console, 'log').mockImplementation(() => {})
		const env = process.env.NODE_ENV
		process.env.NODE_ENV = 'development'
		vm(properties).isConstantOrImmutable('c')
		vm(properties).isConstantOrImmutable('other')
		process.env.NODE_ENV = env
		vm(properties).isConstantOrImmutable('r')
		expect(log.mock.calls).toEqual([
			['Property c detection:', { isConstant: true, isImmutable: false, isImmutableByName: false, schemaProperty: { const: 'x' } }],
		])
	})
})

describe('ViewObject getPropertyDisplayName', () => {
	it.each([
		['t', 'Title'],
		['blank', 'blank'],
		['spaces', 'spaces'],
		['placeholder', 'placeholder'],
		['notString', 'notString'],
		['missing', 'missing'],
	])('names %s as %s', (key, name) => {
		const ctx = vm({
			t: { title: 'Title' },
			blank: { title: '' },
			spaces: { title: '   ' },
			placeholder: { title: ' New ' },
			notString: { title: 5 },
		})
		expect(ctx.getPropertyDisplayName(key)).toBe(name)
	})

	it.each([[null], [''], [7]])('warns about the invalid key %j', (key) => {
		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		expect(vm().getPropertyDisplayName(key)).toBe('Unknown Property')
		expect(warn).toHaveBeenCalledWith('Invalid key passed to getPropertyDisplayName:', key)
	})
})

describe('ViewObject getMarkdownContent', () => {
	it.each([
		[{ getMarkdown: () => '# md', getHTML: () => '<p>' }, '# md'],
		[{ getMarkdown: 'x', getHTML: () => '<p>html</p>' }, '<p>html</p>'],
		['plain text', 'plain text'],
		[null, ''],
		[{}, ''],
		[42, ''],
	])('reads %j', (editor, content) => {
		expect(vm().getMarkdownContent(editor)).toBe(content)
	})

	it('returns empty text when the editor throws', () => {
		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		const editor = {
			getMarkdown: () => {
				throw new Error('gone')
			},
		}
		expect(vm().getMarkdownContent(editor)).toBe('')
		expect(warn).toHaveBeenCalledWith('Error getting content from markdown editor:', expect.any(Error))
	})
})

describe('ViewObject shouldShowDepublishAction', () => {
	beforeEach(() => {
		jest.useFakeTimers().setSystemTime(new Date('2026-06-01T00:00:00Z'))
	})

	it.each([
		[null, false],
		[{}, false],
		[{ publicationDate: '2026-05-01' }, true],
		[{ publicationDate: '2026-06-01T00:00:00Z' }, true],
		[{ publicationDate: '2026-07-01' }, false],
		[{ publicationDate: '2026-05-01', depublicationDate: '2026-07-01' }, true],
		[{ publicationDate: '2026-05-01', depublicationDate: '2026-06-01T00:00:00Z' }, false],
		[{ publicationDate: '2026-05-01', depublicationDate: '2026-05-15' }, false],
		[{ depublicationDate: '2026-07-01' }, false],
	])('for %j is %s', (object, expected) => {
		expect(vm().shouldShowDepublishAction(object)).toBe(expected)
	})
})
