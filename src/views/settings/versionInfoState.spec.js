/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The version information the admin settings page starts with
 * (admin-settings task 10.2).
 */

import { initialVersionInfo } from './versionInfoState.js'

const mockState = { value: null, throws: false }
jest.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => {
		if (mockState.throws) {
			throw new Error('Could not find initial state ' + key + ' of ' + app)
		}
		return mockState.value === null ? fallback : mockState.value
	},
}))

const empty = { appName: '', appVersion: '', configuredVersion: null, versionsMatch: false, needsUpdate: false }

beforeEach(() => {
	mockState.value = null
	mockState.throws = false
})

describe('initialVersionInfo', () => {
	it('starts with the version information the server handed over', () => {
		mockState.value = { appName: 'OpenCatalogi', appVersion: '2.1.0', configuredVersion: '2.0.0', versionsMatch: false, needsUpdate: true }
		expect(initialVersionInfo()).toEqual({ appName: 'OpenCatalogi', appVersion: '2.1.0', configuredVersion: '2.0.0', versionsMatch: false, needsUpdate: true })
	})

	it('fills what the server left out', () => {
		mockState.value = { appVersion: '2.1.0' }
		expect(initialVersionInfo()).toEqual({ ...empty, appVersion: '2.1.0' })
	})

	it('starts empty without initial state', () => {
		expect(initialVersionInfo()).toEqual(empty)
	})

	it('starts empty when the initial state cannot be read', () => {
		mockState.throws = true
		expect(initialVersionInfo()).toEqual(empty)
	})

	it('starts empty when the initial state is not an object', () => {
		mockState.value = 'broken'
		expect(initialVersionInfo()).toEqual(empty)
	})
})
