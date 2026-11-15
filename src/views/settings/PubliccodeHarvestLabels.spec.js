/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the source and flow status lines of `PubliccodeHarvest.vue`.
 */

import PubliccodeHarvest from './PubliccodeHarvest.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: {} }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key]))

/**
 * Evaluate the computed `name` for `status`.
 *
 * @param {string} name The computed property.
 * @param {object} status The harvest status.
 * @return {string} Its value.
 */
function label(name, status) {
	return PubliccodeHarvest.computed[name].call({ ...PubliccodeHarvest.methods, status, t })
}

describe('PubliccodeHarvest status lines', () => {
	it.each([
		[{ source: null }, 'integriq has no GitHub source github-api. Update integriq.'],
		[{ source: { exists: false } }, 'integriq has no GitHub source github-api. Update integriq.'],
		[{ source: { exists: false, slug: 'gh' } }, 'integriq has no GitHub source gh. Update integriq.'],
		[{ source: { exists: true, enabled: false, slug: 'gh' } }, 'The GitHub source gh is switched off. Add the token and switch it on in integriq.'],
		[{ source: { exists: true, enabled: true, slug: 'gh' } }, 'The GitHub source gh is switched on.'],
	])('sourceLabel for %j', (status, expected) => {
		expect(label('sourceLabel', status)).toBe(expected)
	})

	it.each([
		[{ flow: { imported: false } }, 'The harvest flow is not imported yet. Reimport the configuration at the top of this page.'],
		[{ flow: { imported: true, enabled: false } }, 'The harvest is off.'],
		[{ flow: { imported: true, enabled: true, runAs: 'admin', cron: '0 * * * *' } }, 'The harvest is on. It runs as admin on schedule 0 * * * *.'],
		[{ flow: { imported: true, enabled: true } }, 'The harvest is on. It runs as  on schedule .'],
	])('flowLabel for %j', (status, expected) => {
		expect(label('flowLabel', status)).toBe(expected)
	})
})
