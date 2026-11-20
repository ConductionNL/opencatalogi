/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings page names where the Woo-index fields come from
 * (woo-index-fields-from-the-mapping, REQ-WIM-004).
 *
 * WHAT THIS SPEC IS LOOKING FOR. Running the readiness check answers a report
 * whose `mapping` says `integriq` or `built-in`, the fields that came from the
 * mapping, and integriq's refusal when there was one. The readiness card shows
 * that in one line under the result. With integriq answering the mapping the
 * line reads "Woo-index fields from integriq mapping woo-index-publication";
 * without integriq it reads "Woo-index fields built in". The spec asserts the
 * line that matches the report the instance answers, so it runs on an instance
 * with or without integriq. Texts are matched in English and Dutch.
 *
 * Base URL and the admin session come from the environment and
 * playwright.config.ts.
 *
 * @e2e openspec/changes/woo-index-fields-from-the-mapping/specs/woo-compliance/spec.md#scenario-the-settings-page-names-the-source
 */
import { expect, test } from './authenticated-request.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/** The line per source, in English and Dutch. */
const LINES = {
	integriq: /Woo-index fields from integriq mapping woo-index-publication|Woo-indexvelden uit integriq-mapping woo-index-publication/,
	'built-in': /Woo-index fields built in|Woo-indexvelden ingebouwd/,
}

test.describe('Woo-index fields: where they come from', () => {
	test('the readiness report names the mapping source', async ({ request: admin }) => {
		const response = await admin.post(`${API_BASE}/woo/readiness/run`)
		expect(response.ok(), await response.text()).toBe(true)
		const report = await response.json()

		expect(['integriq', 'built-in']).toContain(report.mapping.source)
		expect(Array.isArray(report.mapping.fieldsFromMapping)).toBe(true)
		if (report.mapping.source === 'integriq') {
			expect(report.mapping.fieldsFromMapping.length).toBeGreaterThan(0)
		} else {
			expect(report.mapping.fieldsFromMapping).toEqual([])
		}
	})

	test('the settings page names the source', async ({ page, request: admin }) => {
		await page.goto('/index.php/settings/admin/opencatalogi', { waitUntil: 'domcontentloaded' })

		const run = page.getByRole('button', { name: /Run check|Controle uitvoeren/ }).first()
		await expect(run).toBeVisible({ timeout: 20000 })
		await run.click()

		const line = page.locator('[data-testid="woo-readiness-mapping"]')
		await expect(line).toBeVisible({ timeout: 60000 })

		const stored = await admin.get(`${API_BASE}/woo/readiness`)
		expect(stored.ok(), await stored.text()).toBe(true)
		const body = await stored.json()
		const report = body.report ?? body
		await expect(line).toHaveText(LINES[report.mapping.source as keyof typeof LINES])
		if (report.mapping.refusal) {
			await expect(line).toContainText(report.mapping.refusal.code)
		}
	})
})
