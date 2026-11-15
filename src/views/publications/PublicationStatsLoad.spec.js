/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `PublicationStatsPanel.vue`'s loadStats and
 * `WooBatchDetail.vue`'s loadBatch: success, failure and the loading flag.
 */

import axios from '@nextcloud/axios'
import WooBatchDetail from '../woo/WooBatchDetail.vue'
import PublicationStatsPanel from './PublicationStatsPanel.vue'
import { fetchPublicationStats } from '../../services/usageStats.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../store/store.js', () => ({ objectStore: {} }))
jest.mock('../../dialogs/woo/WooAssessmentDialog.vue', () => ({}))
jest.mock('../../services/usageStats.js', () => ({
	...jest.requireActual('../../services/usageStats.js'),
	fetchPublicationStats: jest.fn(),
}))

describe('PublicationStatsPanel loadStats', () => {
	/**
	 * Run loadStats for `publicationId`.
	 *
	 * @param {string|null} publicationId The publication.
	 * @return {Promise<object>} The state afterwards.
	 */
	async function load(publicationId) {
		const state = {
			...PublicationStatsPanel.methods,
			publicationId,
			loading: true,
			stats: 'old',
		}
		await PublicationStatsPanel.methods.loadStats.call(state)
		return state
	}

	it('stops loading without a publication', async () => {
		fetchPublicationStats.mockReset()
		const state = await load(null)

		expect(state).toMatchObject({ loading: false, stats: 'old' })
		expect(fetchPublicationStats).not.toHaveBeenCalled()
	})

	it('stores the fetched stats', async () => {
		fetchPublicationStats.mockResolvedValue({ views: 3 })
		const state = await load('p1')

		expect(fetchPublicationStats).toHaveBeenCalledWith('p1')
		expect(state).toMatchObject({ loading: false, stats: { views: 3 } })
	})

	it('falls back to empty stats on failure', async () => {
		fetchPublicationStats.mockRejectedValue(new Error('down'))
		const state = await load('p1')

		expect(state.stats).toEqual({
			views: 0,
			downloads: 0,
			series: [],
			countingStart: null,
		})
		expect(state.loading).toBe(false)
	})

	it('gives every failure its own empty stats', async () => {
		fetchPublicationStats.mockRejectedValue(new Error('down'))
		const first = await load('p1')
		const second = await load('p1')

		expect(first.stats).not.toBe(second.stats)
	})
})

describe('WooBatchDetail loadBatch', () => {
	/**
	 * Run loadBatch for batch b1.
	 *
	 * @return {Promise<object>} The state afterwards.
	 */
	async function load() {
		const state = {
			...WooBatchDetail.methods,
			batchId: 'b1',
			loading: false,
			error: 'old',
			batch: null,
		}
		await WooBatchDetail.methods.loadBatch.call(state)
		return state
	}

	beforeEach(() => axios.get.mockReset())

	it('stores the batch', async () => {
		axios.get.mockResolvedValue({ data: { id: 'b1' } })
		const state = await load()

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/woo/batches/b1',
		)
		expect(state).toMatchObject({
			loading: false,
			error: null,
			batch: { id: 'b1' },
		})
	})

	it.each([
		[{ response: { data: { error: 'E' } }, message: 'M' }, 'E'],
		[{ response: { data: {} }, message: 'M' }, 'M'],
		[{ message: 'M' }, 'M'],
	])('shows the error of %j', async (err, expected) => {
		axios.get.mockRejectedValue(err)
		const state = await load()

		expect(state).toMatchObject({ loading: false, error: expected, batch: null })
	})
})
