/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Woo-index connection: robots.txt content, the root rules, and the
 * registration request.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The app's robots.txt has one Sitemap line per
 * line and allows the API paths. The admin endpoint hands out an nginx and an
 * Apache rule for the instance's own base URL. With no gateway installed, a
 * registration request answers 502 with the composed request and changes no
 * status.
 *
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-two-woo-catalogues-and-one-other
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-the-sitemap-paths-are-allowed
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-an-administrator-copies-the-nginx-rule
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-a-failing-check-points-at-the-rule
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-the-gateway-takes-the-request
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-no-gateway-is-installed
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-a-catalogue-is-switched-on
 * @e2e openspec/specs/woo-compliance/spec.md#scenario-nothing-is-woo-enabled
 */
import { expect, test } from './authenticated-request.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

test.describe('Woo-index connection', () => {
	test('robots.txt puts every line on its own line and allows the API', async ({
		request: admin,
	}) => {
		const response = await admin.get(`${API_BASE}/robots.txt`)
		expect(response.ok()).toBe(true)
		const text = await response.text()
		expect(text).not.toContain('\\n')
		expect(text).toContain('Allow: /apps/opencatalogi/api/')
		for (const line of text
			.split('\n')
			.filter((l) => l.startsWith('Sitemap: '))) {
			expect(line).toMatch(
				/^Sitemap: \S+\/sitemaps\/sitemapindex-diwoo-infocat\d{3}\.xml$/,
			)
		}
	})

	test('the admin gets an nginx and an Apache rule for the root robots.txt', async ({
		request: admin,
	}) => {
		const response = await admin.get(`${API_BASE}/woo/registration`)
		expect(response.ok()).toBe(true)
		const body = await response.json()
		expect(body.rules.nginx).toContain('location = /robots.txt')
		expect(body.rules.nginx).toContain(
			'/index.php/apps/opencatalogi/api/robots.txt',
		)
		expect(body.rules.apache).toContain('RewriteRule')
		expect(body.request.robotsTxt).toMatch(/\/robots\.txt$/)
	})

	test('without a gateway a registration request sends nothing and shows the request', async ({
		request: admin,
	}) => {
		const before = await (await admin.get(`${API_BASE}/woo/registration`)).json()
		const response = await admin.post(`${API_BASE}/woo/registration`)
		if (response.status() === 502) {
			const body = await response.json()
			expect(body.sent).toBe(false)
			expect(body.request.robotsTxt).toMatch(/\/robots\.txt$/)
			expect(body.registration.status).toBe(before.registration.status)
		} else {
			// A test instance with integriq and a Woo-index source: the status follows the answer.
			expect(response.ok()).toBe(true)
			expect((await response.json()).registration.status).toBe('requested')
		}
	})
})
