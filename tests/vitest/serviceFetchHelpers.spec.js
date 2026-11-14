/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the request helpers in src/services: publication and catalog
 * usage stats and the current user's groups. Only the HTTP client is mocked.
 */
import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { getCurrentUserGroups } from '../../src/services/nextcloudGroups.js'
import { fetchCatalogStats, fetchPublicationStats } from '../../src/services/usageStats.js'

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => null }))

afterEach(() => {
	vi.restoreAllMocks()
})

describe('usage stats requests', () => {
	it('asks the publication stats endpoint with the options as params', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { views: 3 } })

		await expect(fetchPublicationStats('p-1', { from: '2026-01-01' })).resolves.toEqual({ views: 3 })
		expect(get).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/publications/{id}/stats', { params: { from: '2026-01-01' } })
	})

	it('asks the catalog stats endpoint, with empty params by default', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { views: 9 } })

		await expect(fetchCatalogStats('woo')).resolves.toEqual({ views: 9 })
		expect(get).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/catalogs/{slug}/stats', { params: {} })
	})
})

describe('getCurrentUserGroups', () => {
	it('reads the groups of the current user over OCS', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { ocs: { data: { groups: ['admin', 'staff'] } } } })

		await expect(getCurrentUserGroups()).resolves.toEqual(['admin', 'staff'])
		expect(get).toHaveBeenCalledWith('/ocs/v1.php/cloud/user?format=json', { headers: { 'OCS-APIREQUEST': 'true' } })
	})

	it('is empty when the answer carries no list', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { ocs: { data: { groups: 'admin' } } } })

		await expect(getCurrentUserGroups()).resolves.toEqual([])
	})

	it('is empty when the request fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('down'))
		vi.spyOn(console, 'error').mockImplementation(() => {})

		await expect(getCurrentUserGroups()).resolves.toEqual([])
	})
})
