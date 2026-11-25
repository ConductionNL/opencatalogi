/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Publications list's selection bar opens the mass dialogs on the selected
 * publications (boards OcPubliceren, OcMassaActies, OcSamenvoegen, OcMigreren).
 *
 * WHAT THIS SPEC IS LOOKING FOR. Each action in the selection bar opens its
 * dialog with exactly the rows the user selected, and Merge refuses anything
 * but two. The dialogs' own writes are OpenRegister's and covered there.
 *
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-publishing-two-selected-publications
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-locking-three-selected-publications
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-locked-rows-show-their-new-state
 * @e2e openspec/changes/archive/2026-11-24-publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#scenario-merge-needs-exactly-two
 */
import type { Locator, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

const OR_API = '/index.php/apps/openregister/api'
const RUN_ID = `psa-${Date.now()}`

/**
 * The list row whose text holds a title.
 *
 * @param page The page.
 * @param title The publication title.
 */
function row(page: Page, title: string): Locator {
	return page.locator('main tbody tr').filter({ hasText: title })
}

/**
 * Select the rows of these titles by their checkbox.
 *
 * @param page The page.
 * @param titles The publication titles.
 */
async function select(page: Page, titles: string[]): Promise<void> {
	for (const title of titles) {
		await row(page, title)
			.locator('input[type="checkbox"]')
			.first()
			.check({ force: true })
	}
}

test.describe('Publications selection bar', () => {
	const titles = [1, 2, 3].map((n) => `${RUN_ID} publication ${n}`)
	const created: string[] = []
	let path = ''
	let scope = { register: 0, schema: 0 }

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		path = (await publicationCatalog(request, RUN_ID)).path
		for (const title of titles) {
			const res = await request.post(
				`${OR_API}/objects/${scope.register}/${scope.schema}`,
				{ data: { title, summary: 'Selection bar fixture' } },
			)
			expect(res.ok(), `seed ${title}`).toBe(true)
			created.push(String((await res.json()).id))
		}
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request.delete(
				`${OR_API}/objects/${scope.register}/${scope.schema}/${id}`,
			)
		}
	})

	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP}${path}&_search=${encodeURIComponent(RUN_ID)}`)
		await dismissOverlays(page)
		await expect(row(page, titles[0])).toBeVisible({ timeout: 30_000 })
	})

	/**
	 * Click a bulk action of the selection bar. Lock, Merge and Migrate are
	 * declared with `menu: true` in the manifest, so they sit under the bar's
	 * More menu; Publish and Depublish are inline buttons.
	 *
	 * @param page The page.
	 * @param id The bulk action id.
	 */
	async function bulkAction(page: Page, id: string): Promise<void> {
		const action = page.locator(`[data-testid="cn-bulk-action-${id}"]`).first()
		if (!(await action.isVisible())) {
			await page
				.locator('[data-testid="cn-bulk-more"], [data-testid="cn-bulk-band-more"]')
				.first()
				.getByRole('button')
				.first()
				.click()
		}
		await action.click()
	}

	test('Publish opens the publish dialog on the two selected publications', async ({
		page,
	}) => {
		await select(page, titles.slice(0, 2))
		await page.locator('[data-testid="cn-bulk-action-publish"]').first().click()

		const dialog = page.getByRole('dialog')
		await expect(dialog).toContainText(titles[0])
		await expect(dialog).toContainText(titles[1])
		await expect(dialog).not.toContainText(titles[2])
	})

	test('Lock opens the lock dialog on the three selected publications', async ({
		page,
	}) => {
		await select(page, titles)
		await bulkAction(page, 'lock')

		const dialog = page.getByRole('dialog')
		for (const title of titles) {
			await expect(dialog).toContainText(title)
		}
		await dialog.getByRole('button', { name: /cancel|annuleren/i }).click()
		await expect(page.getByRole('dialog')).toHaveCount(0)
		await expect(
			page.locator('main tbody input[type="checkbox"]:checked'),
		).toHaveCount(0)
	})

	test('Merge with three selected opens nothing', async ({ page }) => {
		await select(page, titles)
		await bulkAction(page, 'merge')

		await expect(page.getByRole('dialog')).toHaveCount(0)
	})

	test('Merge with two selected opens at Configure merge', async ({ page }) => {
		await select(page, titles.slice(0, 2))
		await bulkAction(page, 'merge')

		const dialog = page.getByRole('dialog')
		await expect(dialog).toContainText(titles[0])
		await expect(dialog.locator('.step-2, .merge-step').first()).toBeVisible()
	})

	test('Migrate opens the migration dialog with the selection', async ({
		page,
	}) => {
		await select(page, titles)
		await bulkAction(page, 'migrate')

		const dialog = page.getByRole('dialog')
		for (const title of titles) {
			await expect(dialog).toContainText(title)
		}
	})
})
