/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the staging banner (instance-staging-mode REQ-STG-001): it
 * shows for anything but production.
 */

import { loadState } from '@nextcloud/initial-state'
import { isStaging } from './StagingBanner.vue'

jest.mock('@nextcloud/initial-state', () => ({ loadState: jest.fn() }))
jest.mock('@nextcloud/vue', () => ({ NcNoteCard: {} }))

describe('StagingBanner', () => {
	it('shows while the instance is in staging, or the mode is not production', () => {
		loadState.mockReturnValue('staging')
		expect(isStaging()).toBe(true)
		loadState.mockReturnValue('')
		expect(isStaging()).toBe(true)
	})

	it('stays hidden in production, and when the page carries no mode', () => {
		loadState.mockReturnValue('production')
		expect(isStaging()).toBe(false)
		loadState.mockImplementation((app, key, fallback) => fallback)
		expect(isStaging()).toBe(false)
		expect(loadState).toHaveBeenCalledWith('opencatalogi', 'instance_mode', 'production')
	})
})
