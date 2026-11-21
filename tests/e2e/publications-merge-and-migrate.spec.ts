/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Merge and Migrate in the Publications list's selection bar (boards
 * OcSamenvoegen, OcMigreren).
 *
 * WHAT THIS SPEC IS LOOKING FOR. Merge with exactly two selected publications
 * opens the merge dialog at "Configure merge" and sends one merge request for
 * the first with the second as target; with three selected it opens nothing
 * and says why. Migrate opens the migration dialog with exactly the selected
 * publications, keeps "Migrate objects" disabled until a mapping is set, and
 * sends one migrate request with the selected ids. A signed-in reader sees
 * neither action.
 *
 * WHAT IT CREATES AND REMOVES. Five publications in the publication pair (two
 * for the merge, three for the migration) and one reader account provisioned
 * over OCS. The publications that are left and the account are removed in
 * afterAll.
 *
 * @e2e openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#scenario-merging-a-draft-into-its-final-version
 * @e2e openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#scenario-merge-needs-exactly-two
 * @e2e openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#scenario-migrating-old-imports-to-the-current-schema
 * @e2e openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#scenario-no-mapping-no-migration
 * @e2e openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md#scenario-a-reader-has-no-merge-action
 * @spec openspec/changes/publications-merge-and-migrate/specs/publication-merge-and-migration/spec.md
 */
import type { Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

const OR_API = '/index.php/apps/openregister/api'
const RUN_ID = `pmm-${Date.now()}`

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

/**
 * Click a bulk action in the selection bar, in its More menu when it sits there.
 *
 * @param page The page.
 * @param id The bulk action id.
 * @param label The visible label of the action.
 */
async function bulkAction(page: Page, id: string, label: RegExp): Promise<void> {
	const button = page.locator(`[data-testid="cn-bulk-action-${id}"]`).first()
	if (await button.isVisible().catch(() => false)) {
		await button.click()
		return
	}
	await page.getByRole('button', { name: /more|meer/i }).first().click()
	await page.getByRole('menuitem', { name: label }).first().click()
}

test.describe('Publications merge and migrate', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	const mergeTitles = [
		`${RUN_ID} Decision on the Woo request about the Oostpolder wind farm`,
		`${RUN_ID} Woo decision Oostpolder wind farm, draft`,
	]
	const migrateTitles = [1, 2, 3].map((n) => `${RUN_ID} old Woo import ${n}`)
	const created = new Map<string, string>()
	const reader = {
		uid: `oc-e2e-reader-${RUN_ID}`,
		password: `Oc-${randomBytes(12).toString('hex')}-Pw1!`,
	}
	let path = ''
	let scope = { register: 0, schema: 0 }

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		path = (await publicationCatalog(request, RUN_ID)).path
		for (const title of [...mergeTitles, ...migrateTitles]) {
			const res = await request.post(
				`${OR_API}/objects/${scope.register}/${scope.schema}`,
				{ data: { title, summary: 'Merge and migrate fixture' } },
			)
			expect(res.ok(), `seed ${title}`).toBe(true)
			created.set(title, String((await res.json()).id))
		}
		const made = await request.post('/ocs/v2.php/cloud/users?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
			data: { userid: reader.uid, password: reader.password },
		})
		expect(made.ok(), `provisioning ${reader.uid}: ${await made.text()}`).toBe(true)
	})

	test.afterAll(async ({ request }) => {
		for (const id of created.values()) {
			await request
				.delete(`${OR_API}/objects/${scope.register}/${scope.schema}/${id}`)
				.catch(() => undefined)
		}
		await request
			.delete(`/ocs/v2.php/cloud/users/${reader.uid}?format=json`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
			.catch(() => undefined)
	})

	/**
	 * Open the list filtered to this run's publications.
	 *
	 * @param page The page.
	 */
	async function openList(page: Page): Promise<void> {
		await page.goto(`${APP}${path}&_search=${encodeURIComponent(RUN_ID)}`)
		await dismissOverlays(page)
		await expect(row(page, mergeTitles[0])).toBeVisible({ timeout: 30_000 })
	}

	test('Merge needs exactly two', async ({ page }) => {
		await openList(page)
		await select(page, migrateTitles)
		await bulkAction(page, 'merge', /merge|samenvoegen/i)

		await expect(page.getByRole('dialog')).toHaveCount(0)
		await expect(
			page.getByText(
				/Select exactly two publications to merge|Selecteer precies twee publicaties om samen te voegen/,
			),
		).toBeVisible()
	})

	test('Merging a draft into its final version', async ({ page }) => {
		await openList(page)
		await select(page, mergeTitles)
		await bulkAction(page, 'merge', /merge|samenvoegen/i)

		const dialog = page.getByRole('dialog')
		await expect(dialog).toContainText(/Configure Merge|Samenvoegen configureren/i)
		await dialog
			.getByRole('radio', { name: /Transfer to target object|Overzetten naar doelobject/i })
			.check({ force: true })

		const sourceId = created.get(mergeTitles[0]) as string
		const targetId = created.get(mergeTitles[1]) as string
		const merge = page.waitForRequest(
			(req) =>
				req.method() === 'POST'
				&& req.url().endsWith(`/objects/${scope.register}/${scope.schema}/${sourceId}/merge`),
		)
		await dialog.getByRole('button', { name: /Merge Objects|Objecten samenvoegen/i }).click()

		const body = (await merge).postDataJSON()
		expect(String(body.target)).toBe(targetId)
		expect(body.fileAction).toBe('transfer')
		await expect(dialog).toContainText(/Properties changed|Gewijzigde eigenschappen/i)
		await expect(dialog).toContainText(/Files transferred|Bestanden overgezet/i)

		await dialog.getByRole('button', { name: /close|sluiten/i }).first().click()
		await expect(row(page, mergeTitles[0])).toHaveCount(0)
		created.delete(mergeTitles[0])
	})

	test('No mapping, no migration', async ({ page }) => {
		await openList(page)
		await select(page, migrateTitles)
		await bulkAction(page, 'migrate', /migrate|migreren/i)

		const dialog = page.getByRole('dialog')
		for (const title of migrateTitles) {
			await expect(dialog).toContainText(title)
		}
		await dialog.getByRole('button', { name: /next|volgende/i }).click()
		await dialog.getByLabel(/Target Register|Doelregister/i).click()
		await page.getByRole('option').first().click()
		await dialog.getByLabel(/Target Schema|Doelschema/i).click()
		await page.getByRole('option').first().click()

		await expect(
			dialog.getByRole('button', { name: /Migrate Objects|Objecten migreren/i }),
		).toBeDisabled()
	})

	test('Migrating old imports to the current schema', async ({ page }) => {
		await openList(page)
		await select(page, migrateTitles)
		await bulkAction(page, 'migrate', /migrate|migreren/i)

		const dialog = page.getByRole('dialog')
		await dialog.getByRole('button', { name: /next|volgende/i }).click()
		await dialog.getByLabel(/Target Register|Doelregister/i).click()
		await page.getByRole('option', { name: /publication|publicatie/i }).first().click()
		await dialog.getByLabel(/Target Schema|Doelschema/i).click()
		await page.getByRole('option', { name: /publication|publicatie/i }).first().click()

		for (const property of ['title', 'summary']) {
			const mapping = dialog
				.locator('tr, .mapping-row')
				.filter({ hasText: new RegExp(`^\\s*${property}\\b`, 'i') })
				.first()
			await mapping.getByLabel(/Target property|Doeleigenschap/i).click()
			await page.getByRole('option', { name: new RegExp(`^${property}$`, 'i') }).first().click()
		}
		await expect(dialog).toContainText(/discarded|verwijderd/i)

		const migrate = page.waitForRequest(
			(req) => req.method() === 'POST' && /\/api\/migrate$/.test(req.url()),
		)
		await dialog.getByRole('button', { name: /Migrate Objects|Objecten migreren/i }).click()

		const body = (await migrate).postDataJSON()
		const ids = (body.objects ?? body.selectedObjects ?? body.ids ?? []).map(String)
		expect(ids.sort()).toEqual(migrateTitles.map((t) => created.get(t) as string).sort())
		expect(Object.keys(body.mapping ?? {})).toEqual(expect.arrayContaining(['title', 'summary']))
		await expect(dialog).toContainText(/Objects migrated|Objecten gemigreerd/i)
		await expect(dialog).toContainText(/Objects failed|Objecten mislukt/i)
	})

	test('A reader has no merge action', async ({ browser, baseURL }) => {
		const context = await browser.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
		})
		const page = await context.newPage()
		await page.goto('/index.php/login')
		await page.fill('#user', reader.uid)
		await page.fill('#password', reader.password)
		await page.press('#password', 'Enter')
		await page.waitForURL((url) => !url.pathname.includes('/login'))

		await page.goto(`${APP}${path}&_search=${encodeURIComponent(RUN_ID)}`)
		await dismissOverlays(page)
		await expect(row(page, migrateTitles[0])).toBeVisible({ timeout: 30_000 })
		await select(page, migrateTitles.slice(0, 2))

		await expect(page.locator('[data-testid="cn-bulk-action-merge"]')).toHaveCount(0)
		await expect(page.locator('[data-testid="cn-bulk-action-migrate"]')).toHaveCount(0)
		await context.close()
	})
})
