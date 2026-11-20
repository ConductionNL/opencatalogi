/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * E2E coverage for publication-detail-for-the-portal, tasks 1.2 and 5.2.
 *
 *   REQ-PDP-001, task 1.2: "an officer orders the documents by keyboard and the portal gets that order"
 *   REQ-PDP-005, task 5.2: "a moderator sees a report" (the officer side of "A report reaches the moderators")
 *
 * The other scenarios of the change carry an e2e exclusion in the spec: they
 * are public API contracts and fail-closed paths, proven by the unit tests the
 * tasks name.
 *
 * The officer is the suite's stored admin session. The visitor who reports an
 * error is an anonymous context, and the moderator is a regular user in the
 * catalogue's moderator group with an empty storage state, so neither borrows
 * the admin's rights.
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import {
	BASE,
	Fixtures,
	REG_OPENCATALOGI,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

const API_BASE = '/index.php/apps/opencatalogi/api'
const APP_BASE = '/index.php/apps/opencatalogi'

/**
 * The titles and positions the public attachments endpoint answers.
 *
 * @param anonymous A context without a session.
 * @param slug The catalogue slug.
 * @param id The publication id.
 */
async function publicOrder(
	anonymous: APIRequestContext,
	slug: string,
	id: string,
): Promise<Array<[string, number]>> {
	const res = await anonymous.get(`${API_BASE}/${slug}/${id}/attachments`)
	expect(res.ok(), `public attachments: ${res.status()}`).toBe(true)
	const body = await res.json()
	const rows: Array<Record<string, unknown>> = Array.isArray(body)
		? body
		: (body.results ?? [])
	return rows.map((row) => [
		String(row.title ?? row.name ?? '').replace(/\.txt$/, ''),
		Number(row.position),
	])
}

/**
 * Press a document's Move button with the keyboard.
 *
 * @param page The publication page.
 * @param name The document's name.
 * @param direction up or down.
 */
async function moveByKeyboard(
	page: Page,
	name: string,
	direction: 'up' | 'down',
): Promise<void> {
	const button = page
		.getByTestId('attachment-order')
		.getByRole('button', {
			name: new RegExp(`^Move ${name}(\\.txt)? ${direction}$`),
		})
	await button.focus()
	await page.keyboard.press('Enter')
	await expect(page.getByTestId('attachment-order-live')).toContainText(name)
}

test.describe('Publication detail for the portal', () => {
	const fx = new Fixtures()
	let anonymous: APIRequestContext

	test.beforeAll(async ({ playwright }) => {
		await fx.init()
		anonymous = await playwright.request.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
		})
	})

	test.afterAll(async () => {
		await anonymous.dispose()
		await fx.cleanupAll()
		await fx.dispose()
	})

	// @e2e publications::an-officer-orders-the-documents-by-keyboard-and-the-portal-gets-that-order
	test('an officer orders the documents by keyboard and the portal gets that order', async ({
		page,
		request: admin,
	}) => {
		const slug = `pdp-order-${Date.now().toString(36)}`
		await fx.createCatalog('pdp-order', {
			slug,
			registers: [REG_OPENCATALOGI],
			schemas: [SCHEMA_PUBLICATION],
		})
		const publication = await fx.createPublication(
			'pdp-order',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_OPENCATALOGI,
		)
		for (const name of ['Bijlage 2', 'Besluit', 'Bijlage 1']) {
			await fx.attachFile(
				REG_OPENCATALOGI,
				SCHEMA_PUBLICATION,
				publication.id,
				`${name}.txt`,
				`Inhoud van ${name}`,
			)
		}
		const published = await admin.post(
			`${API_BASE}/publications/${publication.id}/publish`,
		)
		expect(published.ok(), `publish: ${published.status()}`).toBe(true)

		await page.goto(`${APP_BASE}/publications/${slug}/${publication.id}`)
		await expect(page.getByTestId('attachment-order')).toBeVisible()

		// "Besluit" to the top, then "Bijlage 1" above "Bijlage 2".
		await moveByKeyboard(page, 'Besluit', 'up')
		await moveByKeyboard(page, 'Bijlage 1', 'up')
		await expect(page.getByTestId('attachment-order-live')).toContainText('2')

		const save = page.getByTestId('attachment-order-save')
		await save.focus()
		await page.keyboard.press('Enter')
		await expect(
			page.getByText(
				'The order is saved. The portal shows the documents in this order.',
			),
		).toBeVisible()

		await expect
			.poll(() => publicOrder(anonymous, slug, publication.id))
			.toEqual([
				['Besluit', 1],
				['Bijlage 1', 2],
				['Bijlage 2', 3],
			])
	})

	// @e2e publications::a-report-reaches-the-moderators
	test('a moderator sees a report', async ({ browser, request: admin }) => {
		const run = Date.now().toString(36)
		const group = `woo-redactie-${run}`
		const moderator = `moderator-${run}`
		const password = `Oc-e2e-${run}-Mod!`
		const ocs = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
		expect(
			(
				await admin.post('/ocs/v2.php/cloud/groups', {
					headers: ocs,
					data: { groupid: group },
				})
			).ok(),
		).toBe(true)
		expect(
			(
				await admin.post('/ocs/v2.php/cloud/users', {
					headers: ocs,
					data: { userid: moderator, password, groups: [group] },
				})
			).ok(),
		).toBe(true)
		let reportId = ''
		try {
			const slug = `pdp-report-${run}`
			await fx.createCatalog('pdp-report', {
				slug,
				registers: [REG_OPENCATALOGI],
				schemas: [SCHEMA_PUBLICATION],
				acceptErrorReports: true,
				errorReportModerators: [group],
			})
			const publication = await fx.createPublication(
				'pdp-report',
				{ publicationDate: '2026-01-05T09:00:00+00:00' },
				REG_OPENCATALOGI,
			)
			expect(
				(
					await admin.post(
						`${API_BASE}/publications/${publication.id}/publish`,
					)
				).ok(),
			).toBe(true)

			// An anonymous visitor reports the error.
			const message = `De bijlage hoort bij een ander besluit ${run}`
			const posted = await anonymous.post(
				`${API_BASE}/publications/${publication.id}/error-reports`,
				{ data: { message } },
			)
			expect(posted.status()).toBe(201)
			const { reference } = await posted.json()
			expect(String(reference)).not.toBe('')
			reportId = String(reference)

			// The moderator, a regular user in the moderator group, sees it as new.
			const context = await browser.newContext({
				baseURL: BASE,
				storageState: { cookies: [], origins: [] },
			})
			const moderatorPage = await context.newPage()
			await moderatorPage.goto('/index.php/login')
			await moderatorPage.locator('#user').fill(moderator)
			await moderatorPage.locator('#password').fill(password)
			await moderatorPage.locator('#password').press('Enter')
			await moderatorPage.waitForURL((url) => !url.pathname.includes('/login'))
			await moderatorPage.goto(`${APP_BASE}/error-reports`)
			const row = moderatorPage.getByRole('row').filter({ hasText: message })
			await expect(row).toBeVisible()
			await expect(row).toContainText('new')

			// The moderator is notified of it.
			const notifications = await context.request.get(
				'/ocs/v2.php/apps/notifications/api/v2/notifications',
				{ headers: ocs, params: { format: 'json' } },
			)
			expect(notifications.ok()).toBe(true)
			const items: Array<Record<string, string>> =
				(await notifications.json()).ocs?.data ?? []
			expect(
				items.some(
					(item) =>
						item.app === 'openregister' || item.app === 'opencatalogi',
				),
			).toBe(true)
			await context.close()
		} finally {
			if (reportId !== '') {
				await admin.delete(
					`/index.php/apps/openregister/api/objects/${REG_OPENCATALOGI}/errorReport/${reportId}`,
					{ failOnStatusCode: false },
				)
			}
			await admin.delete(`/ocs/v2.php/cloud/users/${moderator}`, {
				headers: ocs,
			})
			await admin.delete(`/ocs/v2.php/cloud/groups/${group}`, { headers: ocs })
		}
	})
})
