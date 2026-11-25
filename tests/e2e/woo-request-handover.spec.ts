/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Woo requests are dossiq's (woo-request-intake-hands-over-to-dossiq, REQ-WHD-005).
 *
 * On an instance without dossiq the Woo settings say that Woo requests are
 * handled by dossiq, which is not installed, and link to the app store, and the
 * request intake answers 404. On an instance with dossiq enabled the scenario
 * does not apply and the test skips itself.
 *
 * @e2e openspec/changes/woo-request-intake-hands-over-to-dossiq/specs/woo-request-intake/spec.md#scenario-an-installation-without-dossiq
 */
import { expect, test } from './authenticated-request.ts'

const API_BASE = '/index.php/apps/opencatalogi/api'
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'

test.describe('Woo requests without dossiq @e2e REQ-WHD-005', () => {
	test('an installation without dossiq', async ({ page, request }) => {
		await page.goto(SETTINGS_PAGE)
		const dossiqEnabled = await page.evaluate(() => Boolean((window as any).OC?.appswebroots?.dossiq))
		test.skip(dossiqEnabled, 'dossiq is enabled on this instance; the scenario needs an instance without it')

		const notice = page.getByTestId('woo-requests-dossiq-missing')
		await expect(notice).toBeVisible()
		await expect(notice).toContainText(/Woo requests are handled by dossiq, which is not installed\.|Woo-verzoeken worden behandeld in dossiq/)
		await expect(page.getByTestId('woo-requests-dossiq-install')).toHaveAttribute('href', /\/settings\/apps$/)

		const intake = await request.post(`${API_BASE}/woo/requests`, { data: { subject: 'e2e-handover-probe' } })
		expect(intake.status()).toBe(404)
	})
})
