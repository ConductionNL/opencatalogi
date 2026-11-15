/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for `UploadObject.vue`'s set-up and editing helpers: on mount it
 * loads the mappings, schemas and registers into select options and clears
 * each loading flag; closing resets the
 * form; the JSON helpers format and check the text. The methods run against
 * a plain `this`, as in UploadObjectUpload.spec.js.
 */

import UploadObject from './UploadObject.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

jest.mock('@codemirror/lang-json', () => ({ json: () => ({}), jsonParseLinter: () => () => [] }))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	catalogStore: { refreshSchemaList: jest.fn(), refreshCatalogiList: jest.fn(), schemaList: [], catalogiList: [] },
	navigationStore: { setModal: jest.fn() },
	objectStore: { getMappings: jest.fn() },
}))

/**
 * A plain `this` with the component's data and methods.
 *
 * @return {object} The context.
 */
function vm() {
	const ctx = { ...UploadObject.data() }
	for (const [name, fn] of Object.entries(UploadObject.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * Let pending then/finally callbacks run.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	catalogStore.schemaList = [{ id: 7, title: 'Besluit' }, { id: 8, title: 'Advies' }]
	catalogStore.catalogiList = [{ id: 3, title: 'Woo' }]
	catalogStore.refreshSchemaList.mockResolvedValue()
	catalogStore.refreshCatalogiList.mockResolvedValue()
	objectStore.getMappings.mockResolvedValue({ data: [{ id: 9, name: 'Import' }] })
})

describe('UploadObject set-up', () => {
	it('loads mappings, schemas and registers into single-select options on mount', async () => {
		const ctx = vm()
		UploadObject.mounted.call(ctx)
		expect([ctx.mappingsLoading, ctx.schemasLoading, ctx.registersLoading]).toEqual([true, true, true])

		await settle()

		const select = (options) => ({ multiple: false, closeOnSelect: true, options, value: null })
		expect(ctx.mappings).toEqual(select([{ id: 9, label: 'Import' }]))
		expect(ctx.schemas).toEqual(select([{ id: 7, label: 'Besluit' }, { id: 8, label: 'Advies' }]))
		expect(ctx.registers).toEqual(select([{ id: 3, label: 'Woo' }]))
		expect([ctx.mappingsLoading, ctx.schemasLoading, ctx.registersLoading]).toEqual([false, false, false])
	})
})

describe('UploadObject closing and JSON helpers', () => {
	it('closes the modal and resets the form', () => {
		const ctx = Object.assign(vm(), { success: true, loading: true, error: 'x', hasUpdated: true, object: '{"a":1}' })
		ctx.closeModal()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(ctx).toMatchObject({ success: null, loading: false, error: false, hasUpdated: false, object: { json: '{}', url: '' } })
	})

	it('prettifies the JSON text with two-space indentation', () => {
		const ctx = Object.assign(vm(), { object: '{"a":1,"b":[2]}' })
		ctx.prettifyJson()
		expect(ctx.object).toBe('{\n  "a": 1,\n  "b": [\n    2\n  ]\n}')
	})

	it.each([
		['{"a":1}', true],
		['[]', true],
		['{', false],
		['', false],
	])('validates %j as %s', (text, valid) => {
		expect(vm().validateJson(text)).toBe(valid)
	})
})
