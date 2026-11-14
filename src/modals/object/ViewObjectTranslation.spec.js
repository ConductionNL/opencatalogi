/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the language switch of `ViewObject.vue` (publication-translations
 * REQ-PTR-003): which languages it offers, what it reads when the editor
 * switches, and what a save in another language sends. Each method runs
 * against a plain `this`, so no component is mounted.
 */

import ViewObject from './ViewObject.vue'
import { requestEditorLanguage } from '../../services/publicationTranslations.js'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))
// @nextcloud/browser-storage ships ESM only, which this Jest setup does not transform.
jest.mock(
	'@nextcloud/browser-storage',
	() => ({
		getBuilder: () => ({
			persist: () => ({ build: () => new Map() }),
			build: () => new Map(),
		}),
	}),
	{ virtual: true },
)
jest.mock('@conduction/nextcloud-vue', () => ({
	dispatchObjectsChanged: jest.fn(),
	CnMetadataTab: {},
	CnPropertiesTab: {},
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/nc' + path.replace(/{(\w+)}/g, (_, k) => params[k]),
}))
jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: {},
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		selectedAttachments: [],
		setActiveObject: jest.fn(),
	},
}))

const publication = {
	id: 'p1',
	title: 'Jaarverslag',
	summary: 'Kort',
	'@self': { id: 'p1', register: 3, schema: 9 },
}
const schema = {
	properties: {
		title: { type: 'string', translatable: true },
		summary: { type: 'string', translatable: true },
		organization: { type: 'string' },
	},
}

/**
 * A plain `this` with the given state, the component's methods and its
 * translation computeds.
 *
 * @param {object} state - The component state
 * @return {object} The context
 */
function vm(state = {}) {
	const ctx = {
		formData: {},
		currentObject: publication,
		isNewObject: false,
		resolvedSchema: schema,
		editLanguage: 'nl',
		editLanguages: ['nl'],
		allTranslations: null,
		translationStatus: null,
		getRegisterSchemaIds: () => ({ registerId: 3, schemaId: 9 }),
		...state,
	}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in ctx)) ctx[name] = fn.bind(ctx)
	}
	Object.defineProperty(ctx, 'translatable', {
		get: () => ViewObject.computed.translatable.call(ctx),
	})
	return ctx
}

/**
 * A fetch double answering per URL and recording each call.
 *
 * @param {object} answers - The JSON answer per URL
 * @return {Array} The calls
 */
function fakeFetch(answers) {
	const calls = []
	global.fetch = jest.fn(async (url, options) => {
		calls.push([url, options])
		return { ok: true, json: async () => answers[url] }
	})
	return calls
}

afterEach(() => {
	delete global.fetch
})

describe('ViewObject language switch (REQ-PTR-003)', () => {
	it('offers the languages enabled in the settings, reads the status, and opens the language asked for', async () => {
		const calls = fakeFetch({
			'/nc/apps/opencatalogi/api/settings': {
				configuration: { publication_languages: '["nl","en"]' },
			},
			'/nc/apps/openregister/api/translations/object/p1?schema=9': {
				translations: [
					{
						property: 'summary',
						language: 'en',
						value: 'Short',
						status: 'outdated',
					},
				],
			},
			'/index.php/apps/openregister/api/objects/3/9/p1?_translations=all': {
				title: { nl: 'Jaarverslag', en: 'Annual report' },
			},
		})
		const ctx = vm()

		await ctx.loadTranslationContext('en')

		expect(ctx.editLanguages).toEqual(['nl', 'en'])
		expect(ctx.editLanguage).toBe('en')
		expect(ViewObject.computed.editLanguageOutdated.call(ctx)).toEqual([
			'summary',
		])
		expect(ctx.allTranslations).toEqual({
			title: { nl: 'Jaarverslag', en: 'Annual report' },
		})
		expect(calls.map(([url]) => url)).toEqual([
			'/nc/apps/opencatalogi/api/settings',
			'/nc/apps/openregister/api/translations/object/p1?schema=9',
			'/index.php/apps/openregister/api/objects/3/9/p1?_translations=all',
		])
	})

	it('reads nothing for a schema without translatable fields', async () => {
		const calls = fakeFetch({})
		const ctx = vm({
			resolvedSchema: { properties: { title: { type: 'string' } } },
		})

		await ctx.loadTranslationContext('en')

		expect(calls).toEqual([])
		expect(ctx.editLanguage).toBe('nl')
	})

	it('opens in Dutch once the language asked for was taken', async () => {
		requestEditorLanguage('en')
		fakeFetch({
			'/nc/apps/opencatalogi/api/settings': { configuration: {} },
			'/nc/apps/openregister/api/translations/object/p1?schema=9': {},
			'/index.php/apps/openregister/api/objects/3/9/p1?_translations=all': {},
		})
		const first = vm()
		await first.initializeData()
		const second = vm()
		await second.initializeData()

		expect(first.editLanguage).toBe('en')
		expect(second.editLanguage).toBe('nl')
	})

	it('shows the fields in the language edited with the other fields read-only', () => {
		const ctx = vm({
			editLanguage: 'en',
			allTranslations: { title: { en: 'Annual report' } },
		})

		const bindings = ViewObject.computed.propertiesPanelBindings.call(ctx)

		expect(bindings.currentObject).toMatchObject({
			title: 'Annual report',
			summary: '',
		})
		expect(bindings.resolvedSchema.properties.organization.readOnly).toBe(true)
		expect(bindings.resolvedSchema.properties.title.readOnly).toBeUndefined()
	})

	it('saves another language as a PATCH of only its translatable fields', async () => {
		const ctx = vm({
			editLanguage: 'en',
			formData: { summary: 'Short', organization: 'org-2' },
		})
		const request = ctx.saveRequest(false)
		const calls = fakeFetch({
			'/index.php/apps/openregister/api/objects/3/9/p1': { id: 'p1' },
		})

		await ctx.sendSave(request)

		expect(request).toMatchObject({
			method: 'PATCH',
			language: 'en',
			objectData: { summary: 'Short' },
		})
		expect(calls[0][0]).toBe('/index.php/apps/openregister/api/objects/3/9/p1')
		expect(calls[0][1].method).toBe('PATCH')
		expect(calls[0][1].headers['X-Translation-Target-Language']).toBe('en')
		expect(JSON.parse(calls[0][1].body)).toEqual({ summary: 'Short' })
	})

	it('saves Dutch as before: a PUT of the whole object without a target language', async () => {
		const ctx = vm({ buildCompleteObjectData: () => ({ title: 'Jaarverslag' }) })
		const request = ctx.saveRequest(false)
		const calls = fakeFetch({
			'/index.php/apps/openregister/api/objects/3/9/p1': { id: 'p1' },
		})

		await ctx.sendSave(request)

		expect(calls[0][1].method).toBe('PUT')
		expect(calls[0][1].headers['X-Translation-Target-Language']).toBeUndefined()
		expect(JSON.parse(calls[0][1].body)).toEqual({ title: 'Jaarverslag' })
	})

	it('switching back to Dutch restores the Dutch form', async () => {
		const ctx = vm({ editLanguage: 'en', formData: { summary: 'Short' } })

		await ctx.setEditLanguage('nl')

		expect(ctx.editLanguage).toBe('nl')
		expect(ctx.formData).toMatchObject({ title: 'Jaarverslag', summary: 'Kort' })
	})
})
