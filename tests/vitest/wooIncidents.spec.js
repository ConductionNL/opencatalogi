/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the Woo pipeline failures in the admin settings
 * (woo-national-output-assurance, REQ-WNO-004). Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	incidentLine,
	incidentStateFrom,
	nobodyReceives,
	WOO_INCIDENT_GROUP_PATH,
	WOO_INCIDENTS_PATH,
} from '../../src/views/settings/wooIncidents.js'

describe('wooIncidents', () => {
	it('reads the group and the open incidents', () => {
		const state = incidentStateFrom({
			group: 'woo-team',
			incidents: [
				{
					id: 'i-1',
					kind: 'plooi-delivery',
					subject: 'pub-1',
					message: "PLOOI delivery of 'Besluit' failed: HTTP 503",
					at: '2026-11-17T10:00:00+00:00',
				},
			],
		})

		expect(state.group).toBe('woo-team')
		expect(state.error).toBe('')
		expect(state.incidents).toEqual([
			{
				id: 'i-1',
				kind: 'plooi-delivery',
				subject: 'pub-1',
				message: "PLOOI delivery of 'Besluit' failed: HTTP 503",
				at: '2026-11-17T10:00:00+00:00',
			},
		])
	})

	it('reads an unreadable answer as no group and no incidents', () => {
		expect(incidentStateFrom(null)).toEqual({
			group: '',
			incidents: [],
			error: '',
		})
		expect(incidentStateFrom({ group: 7, incidents: 'x' })).toEqual({
			group: '',
			incidents: [],
			error: '',
		})
		expect(
			incidentStateFrom({ incidents: [null, { id: 3 }] }).incidents,
		).toEqual([{ id: '3', kind: '', subject: '', message: '', at: '' }])
		expect(
			incidentStateFrom({
				group: 'woo-team',
				error: 'OpenRegister is not available',
			}).error,
		).toBe('OpenRegister is not available')
	})

	it('warns while nobody receives pipeline failures', () => {
		expect(nobodyReceives(null)).toBe(true)
		expect(nobodyReceives({ group: '  ' })).toBe(true)
		expect(nobodyReceives({ group: 'woo-team' })).toBe(false)
	})

	it('lists an incident with its moment and message', () => {
		const formatDate = (value) => 'on ' + value
		expect(
			incidentLine({ message: 'failed', at: '2026-11-17' }, formatDate),
		).toBe('on 2026-11-17: failed')
		expect(incidentLine({ message: 'failed', at: '' }, formatDate)).toBe(
			'failed',
		)
	})

	it('talks to the incident endpoints the routes declare', () => {
		expect(WOO_INCIDENTS_PATH).toBe('/apps/opencatalogi/api/woo/incidents')
		expect(WOO_INCIDENT_GROUP_PATH).toBe(
			'/apps/opencatalogi/api/woo/incidents/group',
		)
	})
})
