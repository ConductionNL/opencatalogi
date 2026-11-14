/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublicationLanguagesWidget.vue` (publication-translations
 * REQ-PTR-004): what it reads, the line it shows per enabled language, and
 * what Translate in the editor opens.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationLanguagesWidget from './PublicationLanguagesWidget.vue'
import { takeRequestedEditorLanguage } from '../../services/publicationTranslations.js'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))
jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: jest.fn() },
	objectStore: { setActiveObject: jest.fn() },
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const SETTINGS_URL = '/apps/opencatalogi/api/settings'
const STATUS_URL = '/apps/openregister/api/translations/object/pub-1'

/**
 * Answer the two reads the widget makes.
 *
 * @param {object} answers The settings and status outcomes.
 * @return {void}
 */
function answerReads({ settings, status }) {
	axios.get.mockImplementation((url) => {
		if (url === SETTINGS_URL) {
			return settings
		}
		if (url === STATUS_URL) {
			return status
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
}

/**
 * Mount the widget for publication pub-1 and let its reads settle.
 *
 * @param {object} [provide] What the detail page provides.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(provide = {}) {
	const wrapper = mount(PublicationLanguagesWidget, {
		props: { objectId: 'pub-1', schema: 'publication' },
		global: { mocks: { t: T }, provide },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	navigationStore.setModal.mockClear()
	objectStore.setActiveObject.mockClear()
	document.documentElement.lang = 'en'
})

describe('PublicationLanguagesWidget', () => {
	it('names a missing English description, with Dutch as the complete source', async () => {
		answerReads({
			settings: Promise.resolve({ data: { configuration: { publication_languages: '["nl","en"]' } } }),
			status: Promise.resolve({
				data: {
					translations: [
						{ property: 'title', language: 'en', value: 'Annual report', status: 'draft' },
						{ property: 'summary', language: 'en', value: 'Short', status: 'draft' },
					],
				},
			}),
		})

		const wrapper = await mountWidget()

		expect(axios.get).toHaveBeenCalledWith(STATUS_URL, { params: { schema: 'publication' } })
		expect(wrapper.get('[data-testid="publication-language-nl"]').text()).toContain('Source language, complete')
		expect(wrapper.find('[data-testid="publication-language-translate-nl"]').exists()).toBe(false)
		const english = wrapper.get('[data-testid="publication-language-en"]').text()
		expect(english).toContain('English')
		expect(english).toContain('Title and summary translated, description missing')
		expect(wrapper.text()).toContain('The public API answers in the language the visitor asks for and falls back to Dutch.')
		expect(wrapper.find('[data-testid="publication-languages-failed"]').exists()).toBe(false)
	})

	it('opens the editor in the language chosen, on this publication', async () => {
		answerReads({
			settings: Promise.resolve({ data: { configuration: { publication_languages: '["nl","de"]' } } }),
			status: Promise.resolve({ data: { translations: [] } }),
		})
		const publication = { id: 'pub-1', title: 'Jaarverslag' }

		const wrapper = await mountWidget({ cnObjectContext: { objectId: 'pub-1', object: publication } })
		expect(wrapper.get('[data-testid="publication-language-de"]').text()).toContain('Not translated yet')
		await wrapper.get('[data-testid="publication-language-translate-de"]').trigger('click')

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', publication)
		expect(navigationStore.setModal).toHaveBeenCalledWith('viewObject')
		expect(takeRequestedEditorLanguage()).toBe('de')
	})

	it('says so when the status cannot be read, and falls back to Dutch and English', async () => {
		answerReads({
			settings: Promise.reject(new Error('down')),
			status: Promise.reject(new Error('down')),
		})

		const wrapper = await mountWidget()

		expect(wrapper.find('[data-testid="publication-languages-failed"]').exists()).toBe(true)
		expect(wrapper.findAll('li').map((item) => item.attributes('data-testid'))).toEqual([
			'publication-language-nl',
			'publication-language-en',
		])
	})
})
