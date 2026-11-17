/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The websites that may call the public API (search-cors-on-every-public-endpoint).
 *
 * An administrator saves two websites on the admin settings page and reads them
 * back after a reload, a browser on one of them gets the search answer with its
 * origin allowed, and a line with a path is refused with that line named. The
 * stored list is restored afterwards.
 *
 * @e2e openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#scenario-an-administrator-adds-the-municipalitys-website
 * @e2e openspec/changes/search-cors-on-every-public-endpoint/specs/cross-origin-api-access/spec.md#scenario-a-line-with-a-path
 */
import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'

const API_BASE = '/index.php/apps/opencatalogi/api'
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'
const FIRST = 'https://www.voorbeeldgemeente.nl'
const SECOND = 'https://data.voorbeeldgemeente.nl:8443'

test.describe('Websites that may call the public API', () => {
	let before = '*'

	test.beforeAll(async ({ request: admin }) => {
		const settings = await (await admin.get(`${API_BASE}/settings`)).json()
		before = String(settings?.configuration?.cors_allowed_origins ?? '*')
	})

	test.afterAll(async ({ request: admin }) => {
		await admin.put(`${API_BASE}/settings`, {
			data: { cors_allowed_origins: before },
		})
	})

	test('an administrator saves two websites and reads them back', async ({
		page,
		request: admin,
		baseURL,
	}) => {
		await page.goto(SETTINGS_PAGE)
		const section = page.getByTestId('cors-allowed-origins')
		const field = section.getByRole('textbox')
		await expect(field).toBeVisible()
		await field.fill(`${FIRST}\n${SECOND}`)

		const saved = page.waitForResponse(
			(response) => response.url().includes('/api/settings') && response.request().method() === 'PUT',
		)
		await section.getByRole('button', { name: /Save websites|Websites opslaan/ }).click()
		expect((await saved).ok()).toBe(true)

		await page.reload()
		await expect(page.getByTestId('cors-allowed-origins').getByRole('textbox')).toHaveValue(
			`${FIRST}\n${SECOND}`,
		)

		const stored = await (await admin.get(`${API_BASE}/settings`)).json()
		expect(String(stored.configuration.cors_allowed_origins)).toContain(FIRST)
		expect(String(stored.configuration.cors_allowed_origins)).toContain(SECOND)

		const browser = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
		})
		const answer = await browser.get(`${API_BASE}/search?_search=e2e-cors-probe`, {
			headers: { Origin: FIRST },
		})
		expect(answer.status()).toBe(200)
		expect(answer.headers()['access-control-allow-origin']).toBe(FIRST)
		await browser.dispose()
	})

	test('a line with a path is refused and named', async ({ page }) => {
		await page.goto(SETTINGS_PAGE)
		const section = page.getByTestId('cors-allowed-origins')
		await section.getByRole('textbox').fill(`${FIRST}/zoeken`)
		await section.getByRole('button', { name: /Save websites|Websites opslaan/ }).click()

		await expect(section).toContainText(`"${FIRST}/zoeken" is not a website origin`)
	})
})
