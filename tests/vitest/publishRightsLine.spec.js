/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the publish-rights line in the admin settings
 * (operations-publish-rights, REQ-OPR-004). Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import { publishRightsLines } from '../../src/views/settings/publishRights.js'

/**
 * A translate double that fills `{name}` placeholders like Nextcloud's.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholders.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	expect(app).toBe('opencatalogi')
	return text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match))
}

const NAMES = 'Only members of the group opencatalogi-publishers and administrators may publish and withdraw publications.'

describe('publishRightsLines', () => {
	it('shows a fresh group with 0 members and says only administrators can publish', () => {
		expect(publishRightsLines({ group: 'opencatalogi-publishers', exists: true, members: 0 }, t)).toEqual([
			NAMES,
			'Members: 0',
			'The group has no members yet, so only administrators can publish.',
		])
	})

	it('shows the member count of a group in use', () => {
		expect(publishRightsLines({ group: 'opencatalogi-publishers', exists: true, members: 4 }, t)).toEqual([
			NAMES,
			'Members: 4',
		])
	})

	it('says how to get a missing group', () => {
		expect(publishRightsLines({ group: 'opencatalogi-publishers', exists: false, members: 0 }, t)).toEqual([
			NAMES,
			'The group does not exist yet. Create it in user management, or run the app\'s repair step.',
		])
	})

	it('says so when the state could not be read', () => {
		expect(publishRightsLines(null, t)).toEqual(['The publish rights could not be read.'])
	})
})
