/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator re-imports the shipped register configuration from the
 * admin settings page (REQ-CRR-004).
 *
 * WHAT THIS SPEC IS LOOKING FOR. Force import on a current configuration posts
 * `{ force: true }` to `/api/settings/import` and the page reports the result;
 * Reimport posts `{ force: false }`. The import on an app update is the
 * repair step's and runs on `occ upgrade`, which a browser cannot drive; this
 * spec reads its outcome from the version info the page shows.
 *
 * WHAT IT CREATES AND REMOVES. Nothing: the import is idempotent on a current
 * configuration.
 *
 * @e2e openspec/changes/capability-rows-get-requirements/specs/retrofit-2026-05-26-app-shell-settings/spec.md#scenario-an-administrator-forces-the-import
 * @e2e openspec/changes/capability-rows-get-requirements/specs/retrofit-2026-05-26-app-shell-settings/spec.md#scenario-an-update-brings-a-newer-configuration
 * @spec openspec/changes/capability-rows-get-requirements/specs/retrofit-2026-05-26-app-shell-settings/spec.md
 */
import { expect, test } from '@playwright/test'
import { dismissOverlays } from './spec-coverage/_nav.ts'

const SETTINGS = '/index.php/settings/admin/opencatalogi'
const IMPORT = '/apps/opencatalogi/api/settings/import'

test.describe('Configuration import on the settings page', () => {
	test.setTimeout(180_000)

	test('An administrator forces the import', async ({ page }) => {
		await page.goto(SETTINGS)
		await dismissOverlays(page)
		const force = page.getByRole('button', {
			name: /Force import|Import forceren/i,
		})
		await expect(force).toBeVisible({ timeout: 30_000 })

		const sent = page.waitForRequest(
			(req) => req.method() === 'POST' && req.url().includes(IMPORT),
		)
		const answered = page.waitForResponse(
			(res) => res.request().method() === 'POST' && res.url().includes(IMPORT),
			{ timeout: 150_000 },
		)
		await force.click()

		expect((await sent).postDataJSON()).toEqual({ force: true })
		const response = await answered
		expect(response.ok(), await response.text()).toBe(true)
		await expect(page.locator('.import-result')).toBeVisible({ timeout: 30_000 })
		await expect(page.locator('.import-result')).not.toBeEmpty()
	})

	test('Reimport sends an unforced import', async ({ page }) => {
		await page.goto(SETTINGS)
		await dismissOverlays(page)
		const reimport = page.getByRole('button', {
			name: /Reimport configuration|Update configuration|Configuratie opnieuw importeren|Configuratie bijwerken/i,
		})
		await expect(reimport).toBeVisible({ timeout: 30_000 })

		const sent = page.waitForRequest(
			(req) => req.method() === 'POST' && req.url().includes(IMPORT),
		)
		await reimport.click()

		expect((await sent).postDataJSON()).toEqual({ force: false })
	})

	test('An update brings a newer configuration', async ({ page }) => {
		await page.goto(SETTINGS)
		await dismissOverlays(page)

		// After `occ upgrade` the repair step has imported the shipped
		// configuration, so the page offers Reimport rather than Update.
		await expect(
			page.getByRole('button', {
				name: /Reimport configuration|Configuratie opnieuw importeren/i,
			}),
		).toBeVisible({ timeout: 30_000 })
	})
})
