/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rights table's helpers (woo-access-boundary-hardening, REQ-WAB-004).
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */
import { describe, expect, it } from 'vitest'
import { grantPayload, groupOptions, rightLabels, rightsRefusal, rightsRows } from '../../src/views/settings/capabilityRights.js'

const t = (_app, text) => text

describe('the rights table', () => {
	it('names each of the eight rights', () => {
		expect(Object.keys(rightLabels(t))).toEqual([
			'openInspection', 'openCommentPeriod', 'depublish', 'announceNationally',
			'decideRetention', 'processWooBatch', 'publishWooBatch', 'requestWooRegistration',
		])
		expect(rightLabels(t).publishWooBatch).toBe('Publish a Woo batch')
	})

	it('offers the groups by name, skipping entries without an id', () => {
		const response = { ocs: { data: { groups: [{ id: 'woo', displayname: 'Woo team' }, { id: 'ter-inzage', displayname: '' }, { displayname: 'no id' }] } } }
		expect(groupOptions(response)).toEqual([{ id: 'ter-inzage', label: 'ter-inzage' }, { id: 'woo', label: 'Woo team' }])
		expect(groupOptions({})).toEqual([])
	})

	it('shows each right with its granted groups, keeping a group the instance no longer has under its id', () => {
		const options = [{ id: 'woo', label: 'Woo team' }]
		const rows = rightsRows({ rights: [
			{ verb: 'openInspection', description: 'Open an inspection period on a publication', groups: ['woo', 'gone'] },
			{ verb: 'futureVerb', description: 'Something new', groups: [] },
		] }, options, t)
		expect(rows).toEqual([
			{ verb: 'openInspection', label: 'Open an inspection period', selected: [{ id: 'woo', label: 'Woo team' }, { id: 'gone', label: 'gone' }] },
			{ verb: 'futureVerb', label: 'Something new', selected: [] },
		])
		expect(rightsRows(null, options, t)).toEqual([])
	})

	it('sends exactly the picked group ids', () => {
		expect(grantPayload([{ id: 'woo', label: 'Woo team' }, { id: 'gone', label: 'gone' }])).toEqual({ groups: ['woo', 'gone'] })
		expect(grantPayload(null)).toEqual({ groups: [] })
	})

	it('says why reading or saving failed', () => {
		expect(rightsRefusal({ response: { status: 403 } }, t)).toBe('Only an administrator can see and change these rights.')
		expect(rightsRefusal({ response: { status: 400, data: { error: 'No such group: nobody' } } }, t)).toBe('No such group: nobody')
		expect(rightsRefusal(new Error('network'), t)).toBe('The rights could not be read or saved. Try again later.')
	})
})
