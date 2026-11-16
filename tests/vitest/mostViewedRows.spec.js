/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The most viewed widget's request, rows and links (ANA-005).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.3
 */
import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchMostViewed, mostViewedRows, publicationPageUrl } from '../../src/services/usageStats.js'

const t = (app, text, vars) => text.replace('{count}', vars.count)

afterEach(() => {
	vi.restoreAllMocks()
})

describe('fetchMostViewed', () => {
	it('asks the instance-wide top endpoint with the options as params', async () => {
		const results = [{ id: 'p-b', title: 'Besluit B', catalog: 'woo', views: 9, downloads: 2 }]
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { results } })

		await expect(fetchMostViewed({ from: '2026-10-01', top: 10 })).resolves.toEqual(results)
		expect(get).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/stats/top', { params: { from: '2026-10-01', top: 10 } })
	})

	it('gives an empty list for an answer without results', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { error: 'x' } })

		await expect(fetchMostViewed()).resolves.toEqual([])
	})
})

describe('mostViewedRows', () => {
	it('lists each publication by title with its views, the id when it has no title', () => {
		expect(mostViewedRows([
			{ id: 'p-b', title: 'Besluit B', catalog: 'woo', views: 1234 },
			{ id: 'p-c', title: '', catalog: 'raad', views: 1 },
		], t)).toEqual([
			{ id: 'p-b', catalog: 'woo', mainText: 'Besluit B', subText: expect.stringMatching(/^1.?234 views$/) },
			{ id: 'p-c', catalog: 'raad', mainText: 'p-c', subText: '1 views' },
		])
	})
})

describe('publicationPageUrl', () => {
	it('opens the publication under the catalogue it was counted in', () => {
		expect(publicationPageUrl({ id: 'p-b', catalog: 'woo' })).toBe('/index.php/apps/opencatalogi/publications/woo/p-b')
		expect(publicationPageUrl({ id: 'a/b', catalog: 'w o' })).toBe('/index.php/apps/opencatalogi/publications/w%20o/a%2Fb')
	})
})
