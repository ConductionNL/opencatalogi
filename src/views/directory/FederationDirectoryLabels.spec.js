/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the listing row labels of
 * `FederationDirectory.vue`: the availability status and the integration
 * level label. Each method runs against a plain `this`.
 */

import FederationDirectory from './FederationDirectory.vue'

jest.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }), { virtual: true })
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({ isAdmin: true }) }))
jest.mock('../../store/store.js', () => ({ navigationStore: {} }))

const { statusFor, integrationLevelFor } = FederationDirectory.methods

describe('FederationDirectory statusFor', () => {
	it.each([
		[{ available: false, statusCode: 200 }, 'down'],
		[{ statusCode: 200 }, 'up'],
		[{ statusCode: 299 }, 'up'],
		[{ statusCode: 300 }, 'degraded'],
		[{ statusCode: 199 }, 'degraded'],
		[{ statusCode: 404 }, 'degraded'],
		[{ statusCode: 500 }, 'down'],
		[{ statusCode: '200' }, 'unknown'],
		[{ available: true }, 'unknown'],
	])('marks %j as %s', (listing, status) => {
		expect(statusFor(listing)).toBe(status)
	})
})

describe('FederationDirectory integrationLevelFor', () => {
	it.each([
		[undefined, 'not set'],
		[null, 'not set'],
		['', 'not set'],
		['search', 'Federated search'],
		['sync', 'Full sync'],
		['none', 'Disabled'],
		['custom', 'custom'],
		['constructor', 'constructor'],
		[3, 3],
	])('labels %j as %j', (integrationLevel, label) => {
		expect(integrationLevelFor({ integrationLevel })).toBe(label)
	})
})
