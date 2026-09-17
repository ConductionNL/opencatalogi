/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from OpenCatalogi's `lib/Settings/connections.json`, with `app` equal
 * to `opencatalogi`. OpenCatalogi writes no row: a save asks integriq to
 * resolve again, a sync, a broadcast or a readiness check reports what it met,
 * and integriq decides the status. So this spec needs integriq installed and
 * synced, and reads the rows from
 * `/apps/openregister/api/objects/integriq/app_connection?app=opencatalogi`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * WHAT A RED HERE USUALLY MEANS. An empty list in the first test means
 * integriq has not synced the declaration, or refused it whole.
 *
 * Locale: nothing forces the E2E language, so statuses are read from the API
 * and rows are found by their declared titles, which are not translated.
 *
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#the-page-lists-only-the-rows-of-opencatalogi
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#add-integration-goes-to-integriq
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

/** The app's own base path, the same one app-chrome.spec.ts uses. */
const APP_BASE = '/apps/opencatalogi'

/** Integriq's objects endpoint for OpenCatalogi's connection rows. */
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection?app=opencatalogi&_limit=50'

/** OpenCatalogi's settings endpoint, admin only. */
const SETTINGS_API = `/index.php${APP_BASE}/api/settings`

/** The declared keys and titles, in declared order. */
const DECLARED = [
	{ key: 'directory', title: 'Federation directories' },
	{ key: 'broadcast', title: 'Directory broadcast' },
	{ key: 'woo-index', title: 'Woo-index harvester' },
]

/** Headers for the JSON API calls. */
const JSON_HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/**
 * OpenCatalogi's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(
	request: APIRequestContext,
): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, { headers: JSON_HEADERS })
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe(
			'opencatalogi',
		)
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/settings/integrations?app=opencatalogi`, {
		timeout: 60_000,
	})
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations over the connection registry', () => {
	test("lists the three declared connections, all of them OpenCatalogi's", async ({
		page,
	}) => {
		const byKey = await rowsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())

		// The two rows with a settings section link into OpenCatalogi's own admin page.
		expect(String(byKey.directory?.settingsUrl ?? '')).toBe(
			'/settings/admin/opencatalogi#section-federation-sync',
		)
		expect(String(byKey['woo-index']?.settingsUrl ?? '')).toBe(
			'/settings/admin/opencatalogi#section-woo-index',
		)

		await openIntegrations(page)
		// Match a row through its Connection cell. A row's accessible name
		// starts with its "Select row" checkbox, so a name anchored on the
		// title can never match (keepiq#717).
		for (const { title } of DECLARED) {
			await expect(
				page.getByRole('row').filter({
					has: page.getByRole('cell', { name: title, exact: true }),
				}),
			).toHaveCount(1)
		}
	})

	test('asks integriq to look again when the Woo-index registration is saved', async ({
		page,
	}) => {
		/**
		 * The woo-index row's refreshedAt, read without asserting: a throw
		 * inside `expect.poll` ends the poll instead of retrying it.
		 *
		 * @return The timestamp, or '' when the row or the field is missing.
		 */
		const refreshedAt = async (): Promise<string> => {
			const list = await page.request.get(CONNECTIONS_API, {
				headers: JSON_HEADERS,
			})
			const rows = list.ok() ? ((await list.json()).results ?? []) : []
			const row = rows.find(
				(r: Record<string, unknown>) =>
					r.key === 'woo-index' && r.app === 'opencatalogi',
			)
			return String(row?.refreshedAt ?? '')
		}

		const settings = await page.request.get(SETTINGS_API, {
			headers: JSON_HEADERS,
		})
		expect(settings.ok(), `settings read -> ${settings.status()}`).toBeTruthy()
		const previous = String(
			(await settings.json())?.configuration?.woo_index_registration_status
				?? 'not_registered',
		)
		const before = await refreshedAt()

		try {
			const res = await page.request.put(SETTINGS_API, {
				headers: JSON_HEADERS,
				data: {
					woo_index_registration_status:
						previous === 'requested' ? 'not_registered' : 'requested',
				},
			})
			expect(res.ok(), `settings save -> ${res.status()}`).toBeTruthy()

			await expect.poll(refreshedAt, { timeout: 15_000 }).not.toBe(before)
		} finally {
			// Put the VALUE back. The restore is a save too, so it refreshes the row again.
			await page.request.put(SETTINGS_API, {
				headers: JSON_HEADERS,
				data: { woo_index_registration_status: previous },
			})
		}
	})

	test('sends Add integration to integriq instead of offering a form', async ({
		page,
	}) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale.
		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(
				/\/apps\/integriq\/connections\?app=opencatalogi&link=1$/,
				{ timeout: 30_000 },
			),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
	})
})
