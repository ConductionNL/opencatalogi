/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the publish right on the publication page
 * (operations-publish-rights, REQ-OPR-002 / REQ-OPR-003): which moves the
 * visibility widget offers, and the words it shows when the server refuses a
 * caller outside the publishers group. Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import {
	publishRefusalMessage,
	visibilityActions,
} from '../../src/services/publicationVisibility.js'

const NONE = { publishNow: false, publishAgain: false, withdraw: false }

describe('visibilityActions', () => {
	it.each([
		['draft', { publishNow: true, publishAgain: false, withdraw: false }],
		['scheduled', { publishNow: true, publishAgain: false, withdraw: true }],
		['public', { publishNow: false, publishAgain: false, withdraw: true }],
		['withdrawn', { publishNow: false, publishAgain: true, withdraw: false }],
		['archived', NONE],
		['', NONE],
	])('offers a publisher the moves that apply to %s', (state, expected) => {
		expect(visibilityActions(state, true)).toEqual(expected)
	})

	it.each(['draft', 'scheduled', 'public', 'withdrawn', 'archived'])(
		'offers an editor outside the group nothing on %s',
		(state) => {
			expect(visibilityActions(state, false)).toEqual(NONE)
		},
	)

	it('treats a missing or non-boolean right as no right', () => {
		expect(visibilityActions('draft', undefined)).toEqual(NONE)
		expect(visibilityActions('draft', 'true')).toEqual(NONE)
	})
})

describe('publishRefusalMessage', () => {
	it('uses the server sentence that names the group', () => {
		const error = {
			response: {
				status: 403,
				data: {
					error: 'not-a-publisher',
					group: 'opencatalogi-publishers',
					message: 'Only members of the group opencatalogi-publishers may publish or withdraw a publication.',
				},
			},
		}
		expect(publishRefusalMessage(error)).toBe(
			'Only members of the group opencatalogi-publishers may publish or withdraw a publication.',
		)
	})

	it('gives nothing for another refusal, so the page keeps its own words', () => {
		expect(publishRefusalMessage({ response: { status: 403, data: { error: 'forbidden', message: 'x' } } })).toBe('')
		expect(publishRefusalMessage({ response: { status: 409, data: {} } })).toBe('')
		expect(publishRefusalMessage({ response: { status: 403, data: { error: 'not-a-publisher' } } })).toBe('')
		expect(publishRefusalMessage(new Error('network'))).toBe('')
		expect(publishRefusalMessage(null)).toBe('')
	})
})
