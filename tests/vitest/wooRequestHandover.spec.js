/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the Woo request lines in the admin settings
 * (woo-request-intake-hands-over-to-dossiq, REQ-WHD-003 and REQ-WHD-005). Offline, no DOM.
 */
import { describe, expect, it } from 'vitest'
import { DOSSIQ_APP_STORE_URL, dossiqMissing, wooRequestHandoverLines } from '../../src/views/settings/wooRequestHandover.js'

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

const date = (value) => 'on ' + value.slice(0, 10)

describe('wooRequestHandoverLines', () => {
	it('says what the hand-over left, and when it was counted', () => {
		expect(wooRequestHandoverLines({ unmigrated: 2, failed: 1, checkedAt: '2026-11-15T10:00:00+00:00', readOnly: false, dossiqInstalled: true }, t, date)).toEqual([
			'Woo requests not yet moved to dossiq: 2',
			'Of those, dossiq could not move: 1',
			'Counted on on 2026-11-15',
		])
	})

	it('says the stored requests are read-only once every one moved', () => {
		const lines = wooRequestHandoverLines({ unmigrated: 0, failed: 0, checkedAt: '', readOnly: true, dossiqInstalled: true }, t, date)

		expect(lines).toEqual([
			'Woo requests not yet moved to dossiq: 0',
			'Of those, dossiq could not move: 0',
			'Every Woo request has moved to dossiq; the requests stored here are read-only.',
		])
	})

	it('says the hand-over has not run before it did', () => {
		expect(wooRequestHandoverLines({ unmigrated: null, failed: null, checkedAt: '', readOnly: false, dossiqInstalled: true }, t, date)).toEqual([
			'The Woo requests stored here have not been handed to dossiq yet.',
		])
	})

	it('shows no counts without dossiq or without an answer', () => {
		expect(wooRequestHandoverLines({ unmigrated: 2, failed: 0, checkedAt: '', readOnly: false, dossiqInstalled: false }, t, date)).toEqual([])
		expect(wooRequestHandoverLines(null, t, date)).toEqual([])
	})
})

describe('dossiqMissing', () => {
	it('is true only when the server says dossiq is not installed', () => {
		expect(dossiqMissing({ dossiqInstalled: false })).toBe(true)
		expect(dossiqMissing({ dossiqInstalled: true })).toBe(false)
		expect(dossiqMissing(null)).toBe(false)
	})

	it('points at dossiq in the app store', () => {
		expect(DOSSIQ_APP_STORE_URL).toBe('https://apps.nextcloud.com/apps/dossiq')
	})
})
