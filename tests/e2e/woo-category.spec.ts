/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Woo publication category: the categories an editor files under.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The category list holds the 18 members of the
 * informatiecategorieen waardelijst, each with a Dutch and an English name, the
 * URI it publishes under and where it came from, so the select on the
 * publication page and the sitemap per category agree on one set of codes.
 * Anonymous callers get nothing.
 *
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-the-category-list-is-read-over-the-api
 */
import { expect, test } from './authenticated-request.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

test.describe('Woo information categories', () => {
	test('the category list holds the 18 codes with both names', async ({
		request: admin,
	}) => {
		const response = await admin.get(`${API_BASE}/woo/categories`)
		expect(response.ok()).toBe(true)

		const body = await response.json()
		expect(body.total).toBe(18)
		// The list follows the administrator's order (REQ-WVL-002), so the codes
		// are compared as a set: infocat001 to infocat018, each once.
		const codes = body.results.map((row: { code: string }) => row.code)
		expect([...codes].sort()).toEqual(
			Array.from({ length: 18 }, (_, i) => `infocat${String(i + 1).padStart(3, '0')}`),
		)
		const annual = body.results.find(
			(row: { code: string }) => row.code === 'infocat012',
		)
		expect(annual.nl).toBe('Jaarplannen en jaarverslagen')
		expect(annual.en).toBe('Annual plans and annual reports')
		expect(annual.origin).toBe('waardelijst')
		expect(annual.sitemap).toBe('sitemapindex-diwoo-infocat012.xml')
		// Every row names the waardelijst member it publishes under, which is the
		// value the national index accepts and nothing else.
		for (const row of body.results) {
			expect(row.uri).toContain(
				'https://identifier.overheid.nl/tooi/def/thes/kern/c_',
			)
		}
	})
})
