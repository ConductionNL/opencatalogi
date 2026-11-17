/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the instance mode requests (instance-staging-mode REQ-STG-001):
 * the answer is read fail-closed, and a change is sent confirmed.
 */

import axios from '@nextcloud/axios'
import { fetchInstanceMode, instanceModeFrom, instanceModeUrl, saveInstanceMode } from './instanceModeRequests.js'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

describe('instanceModeRequests', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('reads the mode and the outside channels', () => {
		expect(instanceModeFrom({ mode: 'production', outside: { webhooks: ['Zaaksysteem'], synchronisations: ['Raadsinformatie'] } }))
			.toEqual({ mode: 'production', webhooks: ['Zaaksysteem'], synchronisations: ['Raadsinformatie'] })
	})

	it('reads an unknown or unreadable mode as staging, and missing lists as empty', () => {
		expect(instanceModeFrom({ mode: 'unreadable' })).toEqual({ mode: 'staging', webhooks: [], synchronisations: [] })
		expect(instanceModeFrom(null)).toEqual({ mode: 'staging', webhooks: [], synchronisations: [] })
		expect(instanceModeFrom({ mode: 'staging', outside: { webhooks: 'x' } }).webhooks).toEqual([])
	})

	it('fetches from the instance mode endpoint', async () => {
		axios.get.mockResolvedValue({ data: { mode: 'staging', outside: { webhooks: [], synchronisations: ['Sync'] } } })

		await expect(fetchInstanceMode()).resolves.toEqual({ mode: 'staging', webhooks: [], synchronisations: ['Sync'] })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings/instance-mode')
		expect(instanceModeUrl()).toBe('/index.php/apps/opencatalogi/api/settings/instance-mode')
	})

	it('sends a change confirmed', async () => {
		axios.post.mockResolvedValue({ data: { mode: 'staging', previous: 'production', changed: true } })

		await expect(saveInstanceMode('staging')).resolves.toEqual({ mode: 'staging', previous: 'production', changed: true })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/settings/instance-mode', { mode: 'staging', confirmed: true })
	})
})
