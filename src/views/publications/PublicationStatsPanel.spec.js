/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublicationStatsPanel.vue`: the loading state, the
 * rendered totals and trends, the counting-start note, and reloading when
 * the publication changes.
 */

import { flushPromises, mount } from '@vue/test-utils'
import PublicationStatsPanel from './PublicationStatsPanel.vue'
import { fetchPublicationStats } from '../../services/usageStats.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('../../services/usageStats.js', () => ({
	...jest.requireActual('../../services/usageStats.js'),
	fetchPublicationStats: jest.fn(),
}))

/**
 * A translate stub that fills {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string} The text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => vars[key])

/**
 * Mount the panel for a publication.
 *
 * @param {string} publicationId The publication.
 * @return {object} The wrapper.
 */
function mountPanel(publicationId = 'p1') {
	return mount(PublicationStatsPanel, {
		props: { publicationId },
		global: { mocks: { t } },
	})
}

/**
 * The text of one element.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} selector The selector.
 * @return {string} Its trimmed text.
 */
const text = (wrapper, selector) => wrapper.find(selector).text().replace(/\s+/g, ' ').trim()

beforeEach(() => fetchPublicationStats.mockReset())

describe('PublicationStatsPanel', () => {
	it('shows the loading icon until the stats arrive', async () => {
		let resolve
		fetchPublicationStats.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = mountPanel()

		expect(wrapper.find('.usageStatCard').exists()).toBe(false)
		resolve({ views: 1, downloads: 0, series: [], countingStart: null })
		await flushPromises()

		expect(wrapper.findAll('.usageStatCard')).toHaveLength(2)
	})

	it('renders the totals and the trend of each metric', async () => {
		fetchPublicationStats.mockResolvedValue({
			views: 1200,
			downloads: 7,
			series: [
				{ views: 1, downloads: 5 },
				{ views: 9, downloads: 1 },
			],
			countingStart: '2026-03-01',
		})
		const wrapper = mountPanel()
		await flushPromises()

		expect(fetchPublicationStats).toHaveBeenCalledWith('p1')
		expect(text(wrapper, '[data-testid="usage-stat-views"] .usageStatValue')).toBe((1200).toLocaleString())
		expect(text(wrapper, '[data-testid="usage-stat-views"] .usageStatTrend')).toBe('Trending up')
		expect(text(wrapper, '[data-testid="usage-stat-downloads"] .usageStatValue')).toBe('7')
		expect(text(wrapper, '[data-testid="usage-stat-downloads"] .usageStatTrend')).toBe('Trending down')
		expect(wrapper.text()).toContain('Counting started on 2026-03-01. Earlier reach is not measured.')
	})

	it('shows a stable trend and the not-measured note for empty stats', async () => {
		fetchPublicationStats.mockRejectedValue(new Error('down'))
		const wrapper = mountPanel()
		await flushPromises()

		expect(text(wrapper, '[data-testid="usage-stat-views"] .usageStatValue')).toBe('0')
		expect(text(wrapper, '[data-testid="usage-stat-views"] .usageStatTrend')).toBe('Stable')
		expect(wrapper.text()).toContain('No usage has been measured yet.')
	})

	it('reloads when the publication changes', async () => {
		fetchPublicationStats.mockResolvedValue({ views: 1, downloads: 0, series: [], countingStart: null })
		const wrapper = mountPanel('p1')
		await flushPromises()

		fetchPublicationStats.mockResolvedValue({ views: 42, downloads: 0, series: [], countingStart: null })
		await wrapper.setProps({ publicationId: 'p2' })
		await flushPromises()

		expect(fetchPublicationStats).toHaveBeenLastCalledWith('p2')
		expect(text(wrapper, '[data-testid="usage-stat-views"] .usageStatValue')).toBe('42')
	})
})
