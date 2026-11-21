/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A CSV with a bad row, published as a table, fails to import: the editor gets
 * a Nextcloud notification naming the table, and the publication page's Tables
 * section shows "Import failed", the refused row, when it failed and Retry.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The failure reaches the editor twice: in
 * Nextcloud's notification list (declared on the publishedTable schema and
 * dispatched by OpenRegister) and on the page the notification links to. The
 * import itself is OpenRegister's and covered there.
 *
 * The CSV has 201 rows: the first 200 give `bedrag` the integer type (the
 * sample ColumnTypes reads), and row 201 holds a word in it, which the import
 * refuses.
 *
 * Base URL and credentials come from the environment (playwright.config.ts).
 *
 * @e2e openspec/changes/data-file-import-failure-alert/specs/open-data-tables/spec.md#scenario-the-editor-follows-the-notification
 */
import type { SeededObject } from './workflows/_fixtures.ts'

import { expect, test } from './authenticated-request.ts'
import { bootApp, navToRoute } from './spec-coverage/_nav.ts'
import {
	Fixtures,
	REG_PUBLICATION,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

const fx = new Fixtures()
const API = '/index.php/apps/opencatalogi/api'
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const FAILED = /Import failed|Import mislukt/
const RETRY = /^(Retry|Opnieuw proberen)$/

let slug: string
let publication: SeededObject
let fileName: string

/**
 * A CSV whose last row holds a word in an integer column.
 *
 * @return The file content.
 */
function csvWithABadRow(): string {
	const rows = ['naam,bedrag']
	for (let i = 1; i <= 200; i++) {
		rows.push(`speeltuin ${i},${i * 10}`)
	}
	rows.push('speeltuin 201,veel')
	return rows.join('\n') + '\n'
}

test.beforeAll(async () => {
	await fx.init()
	slug = `${fx.prefix}-tables`
	await fx.createCatalog('Table import failure', {
		slug,
		registers: [REG_PUBLICATION],
		schemas: [SCHEMA_PUBLICATION],
	})
	publication = await fx.createPublication('Subsidies for playgrounds')
	fileName = `${fx.prefix}-subsidies.csv`
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('a table import that fails', () => {
	test('the editor follows the notification to the failed table', async ({
		page,
		request,
	}) => {
		const fileId = await fx.attachFile(
			REG_PUBLICATION,
			SCHEMA_PUBLICATION,
			publication.id,
			fileName,
			csvWithABadRow(),
		)

		const published = await request.post(
			`${API}/publications/${publication.id}/tables`,
			{ data: { fileId: String(fileId) } },
		)
		expect(published.status()).toBe(201)
		const table = await published.json()
		expect(table.status).toBe('failed')
		expect(String(table.lastError)).toMatch(/20[12]/)
		expect(String(table.failedAt)).not.toBe('')

		// The notification names the table and carries the refused row.
		await expect
			.poll(
				async () => {
					const answer = await request.get(
						'/ocs/v2.php/apps/notifications/api/v2/notifications',
						{ headers: OCS, params: { format: 'json' } },
					)
					if (!answer.ok()) {
						return ''
					}
					return JSON.stringify((await answer.json()).ocs?.data ?? [])
				},
				{
					timeout: 60_000,
					message: 'the editor has a notification naming the table',
				},
			)
			.toContain(fileName)

		// The page it links to shows the failure in the Tables section.
		await bootApp(page)
		await navToRoute(
			page,
			`/publications/${encodeURIComponent(slug)}/${publication.id}`,
		)
		const failure = page.locator(
			`[data-testid="publication-table-failure-${table.id}"]`,
		)
		await expect(failure).toBeVisible({ timeout: 15000 })
		await expect(failure).toContainText(FAILED)
		await expect(failure).toContainText(String(table.lastError))
		await expect(failure).toContainText(/Failed on|Mislukt op/)
		await expect(failure.getByRole('button', { name: RETRY })).toBeVisible()
	})

	test('Retry imports the table again and it fails again on the same row', async ({
		page,
		request,
	}) => {
		const tables = await request.get(
			`${API}/publications/${publication.id}/tables`,
		)
		expect(tables.ok()).toBe(true)
		const failed = ((await tables.json()).results ?? []).find(
			(row: Record<string, unknown>) => row.status === 'failed',
		)
		expect(failed, 'the failed table of the first test').toBeTruthy()

		await bootApp(page)
		await navToRoute(
			page,
			`/publications/${encodeURIComponent(slug)}/${publication.id}`,
		)
		const failure = page.locator(
			`[data-testid="publication-table-failure-${failed.id}"]`,
		)
		await expect(failure).toBeVisible({ timeout: 15000 })
		const retried = page.waitForResponse(
			(response) =>
				response.url().includes(`/tables/${failed.id}/retry`) &&
				response.request().method() === 'POST',
		)
		await failure.getByRole('button', { name: RETRY }).click()
		expect((await retried).status()).toBe(200)

		await expect(
			page.locator('[data-testid="publication-tables-message"]'),
		).toContainText(String(failed.lastError))
		await expect(failure).toContainText(FAILED)
	})
})
