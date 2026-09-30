/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Woo publication category: the 17 categories an editor files under.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The category list has exactly the 17 Woo
 * information categories, each with a Dutch and an English name, so the select
 * on the publication page and the sitemap per category agree on one set of
 * codes. Anonymous callers get nothing.
 *
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-the-category-list-is-read-over-the-api
 */
import { expect, test } from './authenticated-request.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

test.describe('Woo information categories', () => {
	test('the category list holds the 17 codes with both names', async ({
		request: admin,
	}) => {
		const response = await admin.get(`${API_BASE}/woo/categories`)
		expect(response.ok()).toBe(true)

		const body = await response.json()
		expect(body.total).toBe(17)
		const codes = body.results.map((row: { code: string }) => row.code)
		expect(codes[0]).toBe('infocat001')
		expect(codes[16]).toBe('infocat017')
		const annual = body.results.find(
			(row: { code: string }) => row.code === 'infocat012',
		)
		expect(annual.nl).toBe('Jaarplannen en jaarverslagen')
		expect(annual.en).toBe('Annual plans and annual reports')
	})
})
