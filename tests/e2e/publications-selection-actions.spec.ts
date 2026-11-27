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

/*
 * Publish and Depublish on a single row, and what Depublish does with a
 * scheduled publication and a concept (PSA-004, PSA-005).
 *
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-depublishing-one-published-row
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-a-concept-row-offers-no-depublish
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-depublishing-a-scheduled-publication
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-a-concept-is-not-depublished
 * @e2e openspec/specs/publication-selection-actions/spec.md#scenario-only-publish-is-offered-for-a-selected-concept
 */
test.describe('Publish and depublish on a row', () => {
	test.describe.configure({ mode: 'serial' })

	const runId = `psr-${Date.now()}`
	const fixtures = {
		published: { title: `${runId} Raadsstukken voor de vergadering van 15 oktober`, publicationDate: '2020-01-01' },
		scheduled: { title: `${runId} Besluit windpark Oostpolder`, publicationDate: '2099-01-01' },
		concept: { title: `${runId} Concept jaarverslag` },
	}
	const ids: Record<string, string> = {}
	let path = ''
	let scope = { register: 0, schema: 0 }

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		path = (await publicationCatalog(request, runId)).path
		for (const [key, data] of Object.entries(fixtures)) {
			const res = await request.post(
				`${OR_API}/objects/${scope.register}/${scope.schema}`,
				{ data: { summary: 'Row action fixture', ...data } },
			)
			expect(res.ok(), `seed ${key}`).toBe(true)
			ids[key] = String((await res.json()).id)
		}
	})

	test.afterAll(async ({ request }) => {
		for (const id of Object.values(ids)) {
			await request.delete(
				`${OR_API}/objects/${scope.register}/${scope.schema}/${id}`,
			)
		}
	})

	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP}${path}&_search=${encodeURIComponent(runId)}`)
		await dismissOverlays(page)
		await expect(row(page, fixtures.published.title)).toBeVisible({ timeout: 30_000 })
	})

	/**
	 * Open a row's actions menu.
	 *
	 * @param page The page.
	 * @param title The row's title.
	 */
	async function openRowActions(page: Page, title: string): Promise<void> {
		await row(page, title)
			.locator('[data-testid="cn-row-actions"] button')
			.first()
			.click()
	}

	test('Depublish on a published row opens the dialog on that row only', async ({ page }) => {
		await openRowActions(page, fixtures.published.title)
		await page.getByRole('menuitem', { name: /^(Depublish|Depubliceren)$/ }).click()

		const dialog = page.getByRole('dialog')
		await expect(dialog).toContainText(fixtures.published.title)
		await expect(dialog).not.toContainText(fixtures.scheduled.title)
	})

	test('a concept row offers Publish and not Depublish', async ({ page }) => {
		await openRowActions(page, fixtures.concept.title)

		await expect(page.getByRole('menuitem', { name: /^(Publish|Publiceren)$/ })).toBeVisible()
		await expect(page.getByRole('menuitem', { name: /^(Depublish|Depubliceren)$/ })).toHaveCount(0)
	})

	test('a selected concept gets Publish and no Depublish in the selection bar', async ({ page }) => {
		await select(page, [fixtures.concept.title])

		await expect(page.locator('[data-testid="cn-bulk-action-publish"]').first()).toBeVisible()
		await expect(page.locator('[data-testid="cn-bulk-action-depublish"]')).toHaveCount(0)
	})

	test('Depublish skips a concept and takes a scheduled publication back to concept', async ({ page, request }) => {
		await select(page, [fixtures.scheduled.title, fixtures.concept.title])
		await page.locator('[data-testid="cn-bulk-action-depublish"]').first().click()

		const dialog = page.getByRole('dialog')
		await expect(dialog).toContainText(/is a concept and will be skipped|is een concept en wordt overgeslagen/)
		await expect(dialog).toContainText(/goes back to concept|wordt weer een concept/)
		await dialog.getByRole('button', { name: /^(Depublish|Depubliceren)$/ }).click()

		await expect.poll(async () => {
			const res = await request.get(`${OR_API}/objects/${scope.register}/${scope.schema}/${ids.scheduled}`)
			return (await res.json()).publicationDate ?? null
		}, { timeout: 15_000 }).toBeNull()
	})
})
