/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Directory page says when each federation connection last worked
 * (federation-connection-last-success, REQ-FLS-002 and REQ-FLS-003).
 *
 * THE FIXTURE. One listing pointing at a public host that answers, but not
 * with an OpenCatalogi directory, so every sync of it fails. A loopback or
 * private address cannot be used: the outbound-URL guard refuses it before
 * the listing is saved. The healthy-row behaviour (no separate attempt, no
 * stale error) is pinned offline in tests/vitest/listingSyncStatus.spec.js.
 *
 * WHAT A RED HERE USUALLY MEANS. A create that answers 400 means the outbound
 * guard or the test box's network refused example.com, not the page.
 *
 * Locale: nothing forces the E2E language, so the row is found by its URL and
 * the texts are matched in English and Dutch.
 *
 * @e2e openspec/changes/federation-connection-last-success/specs/federation/spec.md#scenario-a-new-peer-that-never-answered
 * @e2e openspec/changes/federation-connection-last-success/specs/federation/spec.md#scenario-an-administrator-checks-a-failing-peer
 * @e2e openspec/changes/federation-connection-last-success/specs/federation/spec.md#scenario-a-user-who-is-not-an-administrator
 */
import { expect, test } from './authenticated-request.ts'

/** The app's base path and API. */
const APP_BASE = '/index.php/apps/opencatalogi'
const API_BASE = `${APP_BASE}/api`

/** A public host that answers, but is no OpenCatalogi directory. */
const FAILING_DIRECTORY = `https://example.com/index.php/apps/opencatalogi/api/directory?e2e=${Date.now()}`

/** A non-admin account, created and removed by this spec. */
const READER = { id: `fls-reader-${Date.now()}`, password: 'Fls-reader-2026-e2e!' }

const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

test.describe('Federation directory: last successful sync', () => {
	let listingId: string | null = null

	test.beforeAll(async ({ request: admin }) => {
		const created = await admin.post(`${API_BASE}/listings`, {
			data: {
				title: 'E2E failing peer',
				directory: FAILING_DIRECTORY,
				integrationLevel: 'sync',
			},
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		listingId = String(body.id ?? body['@self']?.id ?? body.uuid)

		// One failed sync, so the row has an attempt and an error.
		await admin.post(`${API_BASE}/listings/sync`, { data: { id: listingId } })

		const user = await admin.post('/ocs/v2.php/cloud/users', {
			headers: OCS,
			data: { userid: READER.id, password: READER.password },
		})
		expect(user.ok(), await user.text()).toBe(true)
	})

	test.afterAll(async ({ request: admin }) => {
		if (listingId !== null) {
			await admin.delete(`${API_BASE}/listings/${listingId}`)
		}
		await admin.delete(`/ocs/v2.php/cloud/users/${READER.id}`, { headers: OCS })
	})

	test('the listing keeps no success and records the failed attempt with its error', async ({
		request: admin,
	}) => {
		const listing = await (
			await admin.get(`${API_BASE}/listings/${listingId}`)
		).json()
		const data = listing.object ?? listing
		expect(data.lastSync).toBeTruthy()
		expect(data.lastSuccessAt ?? null).toBe(null)
		expect(String(data.lastError)).not.toBe('')
		expect(String(data.lastError)).not.toContain('?e2e=')
	})

	test('an administrator sees that the peer never synchronised, the attempt and the error, and can sync now', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/directory`)
		const row = page.locator('.federation-directory__node', {
			hasText: 'example.com',
		})
		await expect(row).toBeVisible()
		await expect(row.getByTestId('federation-directory-sync')).toContainText(
			/Never synchronised successfully|Nog nooit geslaagd gesynchroniseerd/,
		)
		await expect(row.getByTestId('federation-directory-sync')).toContainText(
			/Last attempt|Laatste poging/,
		)
		await expect(row.locator('.federation-directory__node-error')).toBeVisible()

		await row.getByRole('button', { name: /Actions for|Acties voor/ }).click()
		await expect(
			page.getByRole('menuitem', { name: /Sync now|Nu synchroniseren/ }),
		).toBeVisible()
	})

	test('a user who is not an administrator sees no Sync now', async ({
		baseURL,
		browser,
	}) => {
		const context = await browser.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
		})
		const page = await context.newPage()
		await page.goto('/index.php/login')
		await page.fill('#user', READER.id)
		await page.fill('#password', READER.password)
		await page.press('#password', 'Enter')
		await page.waitForURL((url) => !url.pathname.includes('/login'))

		await page.goto(`${APP_BASE}/directory`)
		// The page itself must have rendered, or "no button" proves nothing.
		await expect(page.locator('.federation-directory__title')).toBeVisible()
		const row = page.locator('.federation-directory__node', {
			hasText: 'example.com',
		})
		if ((await row.count()) > 0) {
			await row
				.getByRole('button', { name: /Actions for|Acties voor/ })
				.click()
		}
		await expect(
			page.getByRole('menuitem', { name: /Sync now|Nu synchroniseren/ }),
		).toHaveCount(0)
		await context.close()
	})
})
