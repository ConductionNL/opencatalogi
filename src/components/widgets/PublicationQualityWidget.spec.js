/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublicationQualityWidget.vue`: the score and the missing
 * fields of the publication in the catalogue the page was opened in (PQM-001).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationQualityWidget from './PublicationQualityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (match, key) => vars?.[key] ?? match),
}))

/**
 * @param {object} [content] - the widget content.
 * @return {Promise<object>} the wrapper.
 */
async function mountWidget(content = {}) {
	const wrapper = mount(PublicationQualityWidget, {
		props: { objectId: 'p-1', content },
		global: {
			mocks: { $route: { params: { catalogSlug: 'woo', id: 'p-1' } } },
			stubs: { NcLoadingIcon: true, NcNoteCard: { template: '<div class="note"><slot /></div>' } },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
})

describe('PublicationQualityWidget', () => {
	it('shows the score and names the missing fields', async () => {
		axios.get.mockResolvedValue({ data: { total: 40, missing: ['dct:license', 'dcat:theme'] } })
		const wrapper = await mountWidget()

		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/catalogs/woo/publications/p-1/quality')
		expect(wrapper.find('[data-testid="publication-quality-score"]').text()).toBe('40 of 100')
		expect(wrapper.findAll('[data-testid="publication-quality-missing"] li').map((item) => item.text())).toEqual(['License', 'Theme'])
	})

	it('says nothing is missing for a complete publication', async () => {
		axios.get.mockResolvedValue({ data: { total: 100, missing: [] } })
		const wrapper = await mountWidget()

		expect(wrapper.find('[data-testid="publication-quality-missing"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Nothing is missing')
	})

	it('uses the catalogue the content names over the route', async () => {
		axios.get.mockResolvedValue({ data: { total: 1, missing: [] } })
		await mountWidget({ catalogSlug: 'raad' })

		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/catalogs/raad/publications/p-1/quality')
	})

	it('says so when the score cannot be loaded', async () => {
		axios.get.mockRejectedValue(new Error('404'))
		const wrapper = await mountWidget()

		expect(wrapper.text()).toContain('The metadata quality could not be loaded')
		expect(wrapper.find('[data-testid="publication-quality-score"]').exists()).toBe(false)
	})
})
