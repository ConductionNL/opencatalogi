/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * instance-staging-mode 1.2 and 2.4: an administrator turns staging on, every
 * page then says so and names what OpenCatalogi does not stop, and an
 * announcement made in staging is kept as a dry run the officer can review.
 *
 * 🔴 IT CHANGES THE INSTANCE MODE. Staging closes the public pages to
 * anonymous visitors and holds every hand-over, so this suite refuses to run
 * on a shared instance, and `afterAll` puts the instance back in production
 * whatever happened.
 *
 * The switch is clicked, because the confirmation is the requirement. The
 * announcement that produces the dry run goes through
 * `POST /api/publications/announce`, the call
 * publication-inspection-and-the-national-indexes.spec.ts already makes.
 *
 * @e2e openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#scenario-an-administrator-turns-staging-on
 * @e2e openspec/changes/instance-staging-mode/specs/admin-settings/spec.md#scenario-the-officer-reviews-what-would-have-gone-out
 * @spec openspec/changes/instance-staging-mode/specs/admin-settings/spec.md
 */

import { expect, test } from '@playwright/test'
import { isSharedInstance } from './shared-instance.ts'

const API_BASE = '/index.php/apps/opencatalogi/api'
const RUN = Date.now().toString(36)

test.describe('Instance staging mode', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	test.beforeAll(({ baseURL }) => {
		if (isSharedInstance(String(baseURL)) === true) {
			throw new Error(
				`instance-staging-mode.spec.ts must not run on ${baseURL}: it switches the whole instance to staging, `
				+ 'which closes the public pages and holds every hand-over for everybody on it. Use a disposable rig.',
			)
		}
	})

	test.afterAll(async ({ request }) => {
		await request.post(`${API_BASE}/settings/instance-mode`, {
			data: { mode: 'production', confirmed: true },
		})
	})

	test('an administrator turns staging on', async ({ page, request }) => {
		await page.goto('/index.php/settings/admin/opencatalogi', { waitUntil: 'domcontentloaded' })
		const staging = page.locator('.instance-mode').getByText('Staging', { exact: true })
		await staging.scrollIntoViewIfNeeded()
		await expect(staging).toBeVisible({ timeout: 30_000 })
		await staging.click()

		const dialog = page.getByRole('dialog', { name: 'Change the instance mode' })
		await expect(dialog).toContainText('Hand-overs are kept as dry runs')
		await dialog.getByRole('button', { name: 'Confirm' }).click()
		await expect(dialog).toBeHidden({ timeout: 20_000 })

		const mode = await request.get(`${API_BASE}/settings/instance-mode`)
		expect(mode.ok(), await mode.text()).toBe(true)
		expect(JSON.stringify(await mode.json())).toContain('staging')

		// What OpenCatalogi does not stop is named in the same section.
		await expect(page.locator('.instance-mode')).toContainText('OpenCatalogi does not stop these')

		// Every page of the app now says so.
		await page.goto('/index.php/apps/opencatalogi/', { waitUntil: 'domcontentloaded' })
		await expect(page.getByTestId('staging-banner')).toContainText('nothing leaves it', { timeout: 30_000 })
	})

	test('the officer reviews what would have gone out', async ({ page, request }) => {
		const announced = await request.post(`${API_BASE}/publications/announce`, {
			data: {
				decision: {
					id: `e2e-staging-${RUN}`,
					title: `E2E bekendmaking in staging ${RUN}`,
					publicationDate: '2026-11-17',
				},
			},
		})
		expect(announced.status(), await announced.text()).toBeLessThan(500)

		await page.goto('/index.php/apps/opencatalogi/dry-runs', { waitUntil: 'domcontentloaded' })
		const rows = page.locator('tbody tr')
		await expect(rows.first(), 'the held announcement is listed as a dry run').toBeVisible({ timeout: 30_000 })
		await expect(page.locator('tbody')).toContainText(/national-index|woo-registration|plooi/)
	})
})
