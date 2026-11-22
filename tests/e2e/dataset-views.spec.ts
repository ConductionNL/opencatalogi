/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An editor adds a view to a published table, and orders and removes the
 * views a table carries.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The editor half of dataset views: the
 * "Show on the public page" action on a ready table opens the view dialog,
 * a bar chart of rows per district saves through the editor route and is
 * listed under its table, and the list's Move and Remove actions change what
 * the editor route answers. The public data answer and its shapes are
 * covered by DatasetViewControllerTest; the portal's drawing of a view is
 * portaliq's.
 *
 * The CSV has 12 playgrounds over three districts, so the table has a text
 * column to group by.
 *
 * Base URL and credentials come from the environment (playwright.config.ts).
 *
 * @e2e openspec/changes/dataset-views-on-the-public-page/specs/open-data-tables/spec.md#scenario-a-bar-chart-of-playgrounds-per-district
 */
import type { Page } from '@playwright/test'
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

const BAR = /^(Bar chart|Staafdiagram)$/
const COUNT = /^(Number of rows|Aantal rijen)$/

let slug: string
let publication: SeededObject
let tableId: string

/**
 * Twelve playgrounds over three districts, with coordinates.
 *
 * @return The file content.
 */
function playgroundsCsv(): string {
	const districts = ['Centrum', 'Noord', 'Zuid']
	const rows = ['naam,wijk,lat,lon']
	for (let i = 1; i <= 12; i++) {
		rows.push(`speeltuin ${i},${districts[i % 3]},52.${10 + i},4.${20 + i}`)
	}
	return rows.join('\n') + '\n'
}

/**
 * Choose an option in one of the dialog's NcSelect pickers.
 *
 * @param page   The page.
 * @param testId The picker's data-testid.
 * @param option The option's visible label.
 */
async function choose(page: Page, testId: string, option: RegExp): Promise<void> {
	await page.locator(`[data-testid="${testId}"]`).click()
	await page.getByRole('option', { name: option }).first().click()
}

/**
 * Open the publication page on its Tables section.
 *
 * @param page The page.
 */
async function openPublication(page: Page): Promise<void> {
	await bootApp(page)
	await navToRoute(
		page,
		`/publications/${encodeURIComponent(slug)}/${publication.id}`,
	)
	await expect(
		page.locator(`[data-testid="publication-table-${tableId}"]`),
	).toBeVisible({ timeout: 15000 })
}

test.beforeAll(async ({ request }) => {
	await fx.init()
	slug = `${fx.prefix}-views`
	await fx.createCatalog('Dataset views', {
		slug,
		registers: [REG_PUBLICATION],
		schemas: [SCHEMA_PUBLICATION],
	})
	publication = await fx.createPublication('Playgrounds per district')
	const fileId = await fx.attachFile(
		REG_PUBLICATION,
		SCHEMA_PUBLICATION,
		publication.id,
		`${fx.prefix}-speeltuinen.csv`,
		playgroundsCsv(),
	)

	const published = await request.post(
		`${API}/publications/${publication.id}/tables`,
		{ data: { fileId: String(fileId) } },
	)
	expect(published.status()).toBe(201)
	tableId = String((await published.json()).id)

	await expect
		.poll(
			async () => {
				const answer = await request.get(
					`${API}/publications/${publication.id}/tables`,
				)
				const rows = (await answer.json()).results ?? []
				const row = rows.find(
					(table: Record<string, unknown>) => String(table.id) === tableId,
				)
				return row?.status
			},
			{ timeout: 60_000, message: 'the table is imported' },
		)
		.toBe('ready')
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('views on a published table', () => {
	test('an editor adds a bar chart', async ({ page, request }) => {
		await openPublication(page)
		await page
			.locator(`[data-testid="publication-table-add-view-${tableId}"]`)
			.click()

		const dialog = page.locator('[data-testid="dataset-view-dialog"]')
		await expect(dialog).toBeVisible()
		await choose(page, 'dataset-view-kind', BAR)
		await dialog
			.locator('[data-testid="dataset-view-title"] input')
			.fill('Playgrounds per district')
		await choose(page, 'dataset-view-x', /^wijk$/)
		await choose(page, 'dataset-view-aggregate', COUNT)

		const saved = page.waitForResponse(
			(response) =>
				response.url().includes(`/tables/${tableId}/views`) &&
				response.request().method() === 'POST',
		)
		await dialog.locator('[data-testid="dataset-view-save"]').click()
		const answer = await saved
		expect(answer.ok()).toBe(true)
		const view = await answer.json()
		expect(view.kind).toBe('bar')

		// The preview shows one group per district.
		await expect(
			dialog.locator('[data-testid="dataset-view-preview"]'),
		).toContainText(/3/)

		// The editor route lists it, and the page lists it under the table.
		const listed = await request.get(
			`${API}/publications/${publication.id}/tables/${tableId}/views`,
		)
		expect(listed.ok()).toBe(true)
		expect(JSON.stringify(await listed.json())).toContain(
			'Playgrounds per district',
		)

		await page.keyboard.press('Escape')
		await expect(
			page.locator(`[data-testid="publication-table-view-${view.id}"]`),
		).toContainText('Playgrounds per district')
	})

	test('an editor reorders and removes a view', async ({ page, request }) => {
		const route = `${API}/publications/${publication.id}/tables/${tableId}/views`
		const second = await request.post(route, {
			data: { kind: 'table', title: 'All playgrounds', columns: ['naam', 'wijk'] },
		})
		expect(second.ok()).toBe(true)
		const secondId = String((await second.json()).id)

		const titles = async (): Promise<string[]> => {
			const answer = await request.get(route)
			const body = await answer.json()
			const views = Array.isArray(body) ? body : (body.results ?? body.views ?? [])
			return views.map((view: Record<string, unknown>) => String(view.title))
		}
		expect(await titles()).toEqual(['Playgrounds per district', 'All playgrounds'])

		await openPublication(page)
		await page
			.locator(`[data-testid="publication-table-view-up-${secondId}"]`)
			.click()
		await expect.poll(titles, { timeout: 15000 }).toEqual([
			'All playgrounds',
			'Playgrounds per district',
		])

		await page
			.locator(`[data-testid="publication-table-view-remove-${secondId}"]`)
			.click()
		await expect(
			page.locator('[data-testid="publication-tables-message"]'),
		).toContainText('All playgrounds')
		await expect.poll(titles, { timeout: 15000 }).toEqual([
			'Playgrounds per district',
		])
		await expect(
			page.locator(`[data-testid="publication-table-view-${secondId}"]`),
		).toHaveCount(0)
	})
})
