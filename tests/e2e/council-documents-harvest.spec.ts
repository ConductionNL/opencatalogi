/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Council documents" section of the OpenCatalogi admin settings
 * (council-documents-from-notubiz-and-ibabs, task 3.1).
 *
 * WHAT THIS NEEDS. integriq installed and enabled, with its `notubiz-ris-v1`
 * source present and its organisation id filled in. Without integriq the
 * section shows its "needs integriq" notice and the set-up test is skipped
 * with that reason, because nothing in OpenCatalogi can make it pass.
 *
 * WHAT IT LEAVES BEHIND. Set up writes the integriq synchronization
 * `opencatalogi-council-documents-notubiz` and the file rule
 * `opencatalogi-council-document-file-notubiz`; set up is idempotent, so a
 * second run finds them again. The harvest is switched off again at the end,
 * so the nightly schedule never starts reading a real council system.
 *
 * The base URL comes from the Playwright config (NEXTCLOUD_URL); the admin
 * session from the global setup. The refusal test uses its own context with
 * an empty storage state.
 *
 * @e2e openspec/changes/council-documents-from-notubiz-and-ibabs/specs/council-documents-harvest/spec.md#setting-up-from-a-notubiz-source
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

/** The admin settings page of OpenCatalogi. */
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'

/** The council harvest status endpoint, admin only. */
const STATUS_API = '/index.php/apps/opencatalogi/api/settings/council-harvest'

/** Headers for the JSON API calls. */
const JSON_HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/** The integriq source the Notubiz harvest reads. */
const NOTUBIZ_SOURCE = 'notubiz-ris-v1'

/**
 * The harvest's status as the section reads it.
 *
 * @param request An admin request context.
 * @return The status body.
 */
async function status(request: APIRequestContext): Promise<Record<string, unknown>> {
	const res = await request.get(STATUS_API, { headers: JSON_HEADERS })
	expect(res.ok(), `GET council-harvest -> ${res.status()}`).toBeTruthy()
	return (await res.json()) as Record<string, unknown>
}

test.describe('Council documents harvest settings', () => {
	test('an administrator sets up the council harvest', async ({ page }) => {
		const before = await status(page.request)
		test.skip(before.integriq !== true, 'integriq is not enabled on this instance')
		const sources = (before.sources ?? []) as Array<Record<string, unknown>>
		test.skip(
			!sources.some((s) => s.slug === NOTUBIZ_SOURCE),
			`integriq has no ${NOTUBIZ_SOURCE} source on this instance`,
		)

		await page.goto(SETTINGS_PAGE, { timeout: 60_000 })
		const section = page.getByTestId('council-harvest')
		await expect(section).toBeVisible({ timeout: 30_000 })

		// Pick the Notubiz source in the Source select and press Set up.
		await section.getByTestId('council-harvest-source').click()
		await page.getByRole('option', { name: /Notubiz/ }).first().click()
		const setUp = page.waitForResponse(
			(r) => r.url().includes('/api/settings/council-harvest/setup') && r.request().method() === 'POST',
		)
		await section.getByTestId('council-harvest-setup').click()
		const answer = await setUp
		expect(answer.status(), `set up -> ${answer.status()}: ${await answer.text()}`).toBe(200)

		// The synchronization and the file rule exist, and the section offers Run now and Switch on/off.
		const after = await status(page.request)
		expect(after.source).toBe(NOTUBIZ_SOURCE)
		await expect(section.getByTestId('council-harvest-run')).toBeVisible()
		await expect(
			section.getByTestId('council-harvest-enable').or(section.getByTestId('council-harvest-disable')),
		).toBeVisible()

		// Switch it on and off again, so the nightly schedule is never left running here.
		if (await section.getByTestId('council-harvest-enable').isVisible()) {
			await section.getByTestId('council-harvest-enable').click()
			await expect(section.getByTestId('council-harvest-disable')).toBeVisible({ timeout: 30_000 })
		}
		await section.getByTestId('council-harvest-disable').click()
		await expect(section.getByTestId('council-harvest-enable')).toBeVisible({ timeout: 30_000 })
		expect(((await status(page.request)).flow as Record<string, unknown> | null)?.enabled ?? false).toBe(false)
	})

	test('a visitor without a session cannot read or set up the harvest', async ({ browser }) => {
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		try {
			const read = await context.request.get(STATUS_API, { headers: JSON_HEADERS })
			expect(read.ok(), `anonymous GET council-harvest -> ${read.status()}`).toBeFalsy()

			const setUp = await context.request.post(`${STATUS_API}/setup`, {
				headers: JSON_HEADERS,
				data: { source: NOTUBIZ_SOURCE },
			})
			expect(setUp.ok(), `anonymous POST council-harvest/setup -> ${setUp.status()}`).toBeFalsy()
		} finally {
			await context.close()
		}
	})
})
