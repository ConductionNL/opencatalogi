/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `CatalogRetentionDefaultsFields.vue`: the rows come from
 * the Woo categories and the stored defaults, and a save posts the whole map
 * with only this catalogue's entry changed (RET-004).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-3.1
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import CatalogRetentionDefaultsFields from './CatalogRetentionDefaultsFields.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (match, key) => vars?.[key] ?? match),
	getLanguage: () => 'nl',
}))

const STORED = {
	woo: { infocat012: { termMonths: 12, action: 'depublish' } },
	other: { _fallback: { termMonths: 60, action: 'archive' } },
}

/**
 * @return {Promise<object>} the mounted wrapper.
 */
async function mountFields() {
	axios.get.mockImplementation((url) => Promise.resolve({
		data: url.endsWith('/woo/categories')
			? { results: [{ code: 'infocat001', nl: 'Wetten', en: 'Laws' }, { code: 'infocat012', nl: 'Vergunningen', en: 'Permits' }] }
			: { defaults: STORED, warningWindowDays: 30 },
	}))
	const wrapper = mount(CatalogRetentionDefaultsFields, {
		props: { catalogSlug: 'woo' },
		global: { stubs: { NcSelect: true, NcTextField: true, NcLoadingIcon: true, NcNoteCard: { template: '<div class="note"><slot /></div>' } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('CatalogRetentionDefaultsFields', () => {
	it('loads the categories and defaults and shows one row per category after the fallback', async () => {
		const wrapper = await mountFields()

		expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
			'/apps/opencatalogi/api/woo/categories',
			'/apps/opencatalogi/api/retention/defaults',
		])
		const labels = wrapper.findAll('tbody th').map((cell) => cell.text())
		expect(labels).toEqual(['Any other category', 'Wetten', 'Vergunningen'])
		expect(wrapper.vm.rows[2]).toEqual({ code: 'infocat012', label: 'Vergunningen', termMonths: '12', action: 'depublish' })
	})

	it('posts the map with only this catalogue changed', async () => {
		axios.post.mockResolvedValue({ data: { defaults: { saved: true } } })
		const wrapper = await mountFields()
		wrapper.vm.rows[0].action = 'review'
		wrapper.vm.rows[2].termMonths = '24'

		await wrapper.find('[data-testid="catalog-retention-defaults-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/retention/defaults', {
			defaults: {
				other: { _fallback: { termMonths: 60, action: 'archive' } },
				woo: { _fallback: { action: 'review' }, infocat012: { termMonths: 24, action: 'depublish' } },
			},
		})
		expect(wrapper.text()).toContain('Retention defaults saved')
		expect(wrapper.vm.defaults).toEqual({ saved: true })
	})

	it('does not save a term that is not a whole number of months', async () => {
		const wrapper = await mountFields()
		wrapper.vm.rows[1].termMonths = '1.5'
		await flushPromises()

		expect(wrapper.find('[data-testid="catalog-retention-defaults-save"]').attributes('disabled')).toBeDefined()
	})

	it('shows the server refusal when the save fails', async () => {
		axios.post.mockRejectedValue({ response: { data: { error: 'Invalid retention action "x" for woo/infocat001' } } })
		const wrapper = await mountFields()

		await wrapper.find('[data-testid="catalog-retention-defaults-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Invalid retention action "x" for woo/infocat001')
	})

	it('says so when the defaults cannot be loaded', async () => {
		axios.get.mockRejectedValue(new Error('403'))
		const wrapper = mount(CatalogRetentionDefaultsFields, {
			props: { catalogSlug: 'woo' },
			global: { stubs: { NcLoadingIcon: true, NcNoteCard: { template: '<div class="note"><slot /></div>' } } },
		})
		await flushPromises()

		expect(wrapper.text()).toContain('The retention defaults could not be loaded')
		expect(wrapper.find('table').exists()).toBe(false)
	})
})
