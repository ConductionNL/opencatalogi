/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the language switch of `CatalogModal.vue` (publication-translations
 * REQ-PTR-003): the languages offered, the text shown in another language,
 * the shared fields locked there, and what a save in that language writes.
 */

import { flushPromises, mount } from '@vue/test-utils'
import CatalogModal from './CatalogModal.vue'
import { requestEditorLanguage } from '../../services/publicationTranslations.js'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('../../composables/useIsAdmin.js', () => ({
	useIsAdmin: () => ({ isAdmin: { value: false }, loaded: { value: true } }),
}))
jest.mock('../../services/nextcloudGroups.js', () => ({
	getNextcloudGroups: () => Promise.resolve([]),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/nc' + path.replace(/{(\w+)}/g, (_, k) => params[k]),
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'catalog', setModal: jest.fn() },
	objectStore: {
		availableRegisters: [],
		availableSchemas: [],
		active: null,
		getActiveObject() {
			return this.active
		},
		getCollection: () => ({ results: [] }),
		getState: () => ({ success: null }),
		isLoading: () => false,
		setState: jest.fn(),
		createObject: jest.fn(() => Promise.resolve()),
		updateObject: jest.fn(() => Promise.resolve()),
		saveObjectTranslation: jest.fn(() => Promise.resolve(true)),
	},
}))

const catalog = {
	id: 'cat-1',
	title: 'Woo-publicaties',
	summary: 'Alle Woo-documenten',
	description: 'Lang',
	slug: 'woo',
	status: 'stable',
	registers: [],
	schemas: [],
	'@self': { id: 'cat-1', register: 3, schema: 7 },
}

const answers = {
	'/nc/apps/opencatalogi/api/settings': {
		configuration: { publication_languages: '["nl","en"]' },
	},
	'/nc/apps/openregister/api/translations/object/cat-1?schema=7': {
		translations: [
			{
				property: 'summary',
				language: 'en',
				value: 'All Woo documents',
				status: 'outdated',
			},
		],
	},
	'/index.php/apps/openregister/api/objects/3/7/cat-1?_translations=all': {
		title: { nl: 'Woo-publicaties', en: 'Woo publications' },
		summary: { nl: 'Alle Woo-documenten', en: 'All Woo documents' },
		description: 'Lang',
	},
}

/**
 * Mount the modal on the catalogue and let it fill itself.
 *
 * @return {Promise<object>} The modal.
 */
async function openCatalog() {
	objectStore.active = catalog
	const wrapper = mount(CatalogModal, {
		global: {
			mocks: {
				t: (app, text, vars = {}) =>
					text.replace(/{(\w+)}/g, (m, k) => vars[k] ?? m),
			},
		},
	})
	CatalogModal.updated.call(wrapper.vm.$.proxy)
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.useRealTimers()
	navigationStore.modal = 'catalog'
	objectStore.saveObjectTranslation.mockClear()
	objectStore.updateObject.mockClear()
	global.fetch = jest.fn(async (url) => ({
		ok: true,
		json: async () => answers[url],
	}))
})

afterEach(() => {
	delete global.fetch
})

describe('CatalogModal language switch (REQ-PTR-003)', () => {
	it('offers Dutch and the enabled languages and starts in Dutch', async () => {
		const wrapper = await openCatalog()

		expect(wrapper.vm.editLanguages).toEqual(['nl', 'en'])
		expect(wrapper.vm.editLanguage).toBe('nl')
		expect(wrapper.vm.textFields.title).toBe('Woo-publicaties')
		wrapper.vm.textFields.title = 'Woo'
		expect(wrapper.vm.catalogi.title).toBe('Woo')
		expect(
			wrapper.find('[data-testid="translation-language-switch"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('fieldset.catalog-shared-fields').attributes('disabled'),
		).toBeUndefined()
	})

	it('shows the English text with the shared fields locked and the outdated field named', async () => {
		requestEditorLanguage('en')
		const wrapper = await openCatalog()

		expect(wrapper.vm.editLanguage).toBe('en')
		expect(wrapper.vm.textFields).toEqual({
			title: 'Woo publications',
			summary: 'All Woo documents',
			description: '',
		})
		expect(wrapper.vm.editLanguageOutdated).toEqual(['summary'])
		expect(
			wrapper.find('fieldset.catalog-shared-fields').attributes('disabled'),
		).toBeDefined()
	})

	it('saves only the English fields the editor changed, and leaves the Dutch catalogue alone', async () => {
		jest.useFakeTimers()
		requestEditorLanguage('en')
		const wrapper = await openCatalog()
		wrapper.vm.textFields.description = 'Long'

		await wrapper.vm.saveCatalog()

		expect(objectStore.saveObjectTranslation).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/3/7/cat-1',
			{ fields: { description: 'Long' }, language: 'en' },
		)
		expect(objectStore.updateObject).not.toHaveBeenCalled()
		expect(wrapper.vm.catalogi.description).toBe('Lang')
		expect(wrapper.vm.translationError).toBe('')
	})

	it('says so when the translation could not be saved', async () => {
		objectStore.saveObjectTranslation.mockResolvedValueOnce(false)
		requestEditorLanguage('en')
		const wrapper = await openCatalog()

		await wrapper.vm.saveCatalog()

		expect(wrapper.vm.translationError).toBe(
			'The translation could not be saved. Try again.',
		)
	})

	it('keeps the form in Dutch when the settings cannot be read', async () => {
		global.fetch = jest.fn(async () => {
			throw new Error('down')
		})
		requestEditorLanguage('en')
		const wrapper = await openCatalog()

		expect(wrapper.vm.editLanguages).toEqual(['nl'])
		expect(wrapper.vm.editLanguage).toBe('nl')
		expect(
			wrapper.find('[data-testid="translation-language-switch"]').exists(),
		).toBe(false)
	})
})
