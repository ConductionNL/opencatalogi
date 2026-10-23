/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The catalogue page's staff access line (catalogue-staff-access REQ-CSA-003).
 */
import { describe, expect, it } from 'vitest'
import { staffAccessLine } from '../../src/services/catalogStaffAccess.js'

describe('staffAccessLine', () => {
	it('says all staff for an unrestricted catalogue', () => {
		expect(staffAccessLine(null)).toBe('All staff')
		expect(staffAccessLine({ restricted: false, editGroups: ['griffie'] })).toBe(
			'All staff',
		)
	})

	it('names the groups of a restricted catalogue once each', () => {
		expect(
			staffAccessLine({
				restricted: true,
				editGroups: ['Griffie'],
				readGroups: ['Woo-team', 'Griffie'],
			}),
		).toBe('Restricted to Griffie, Woo-team')
	})

	it('says administrators when a restricted catalogue names no group', () => {
		expect(staffAccessLine({ restricted: true })).toBe(
			'Restricted to administrators',
		)
	})
})
