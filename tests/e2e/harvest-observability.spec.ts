/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The harvest feed page: an administrator reads why a run was partial.
 *
 * WHAT THIS SPEC IS LOOKING FOR. A feed runs on a fixture catalogue of three
 * datasets, one of them without `dct:title`. The fetcher refuses that dataset
 * against the bundled DCAT-AP-NL shape, so the run is `partial`. The
 * administrator opens the feed's page from its card's name, finds the run in
 * the "Runs" list with status partial, opens it, and reads the refused
 * dataset by its `@id` with the reason "dct:title: Less than 1 values".
 *
 * DEPENDS ON. Everything `harvest-feed.spec.ts` depends on (the feed section,
 * the feed modal, OpenRegister's source routes and `POST /api/sources/{id}/sync`),
 * plus this change's SHACL validation in the fetcher (tasks 1.1 and 1.2) and the
 * page `HarvestFeed` at `/settings/harvest/:sourceId` with
 * `src/views/settings/HarvestFeedDetail.vue` (task 2.2), reading the flow's runs
 * through `GET /apps/openregister/api/flow-runs`. The fixture catalogue with
 * one untitled dataset is not named by the tasks; this spec reads it from
 * `HARVEST_INVALID_FIXTURE_URL`, by default
 * `tests/fixtures/harvest/dcat-catalog-one-untitled.jsonld` served from the app
 * directory on the instance under test, which the Nextcloud container must be
 * able to reach and OpenRegister's outbound guard must allow. The page shows the
 * runs in a region headed "Runs", one row per run, and a run's errors open in a
 * dialog. Until all of this is on the instance this spec fails at its first
 * request; it is written now from the change's scenarios so the build that adds
 * them has its acceptance test waiting.
 *
 * WHAT IT LEAVES BEHIND. Nothing: the source, the two harvested publications
 * and the fixture catalogue are removed in afterAll.
 *
 * @e2e openspec/changes/harvest-observability/specs/harvest-observability/spec.md#scenario-an-administrator-reads-why-a-run-was-partial
 * @spec openspec/changes/harvest-observability/tasks.md#task-2.2
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { dismissOverlays } from './spec-coverage/_nav.ts'
import { BASE, Fixtures, newRunId, REG_PUBLICATION, SCHEMA_PUBLICATION } from './workflows/_fixtures.ts'

/** OpenRegister's API base path. */
const OR_API = '/index.php/apps/openregister/api'

/** The app's page base path. */
const APP = '/index.php/apps/opencatalogi'

/** The admin settings page that holds the "Harvest feeds" section. */
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'

/** A catalogue of three datasets, one without `dct:title`. */
const FIXTURE_URL = process.env.HARVEST_INVALID_FIXTURE_URL
	?? `${BASE}/apps/opencatalogi/tests/fixtures/harvest/dcat-catalog-one-untitled.jsonld`

/** The reason REQ-HOB-001 names for a dataset without a title. */
const REASON = 'dct:title: Less than 1 values'

/**
 * Pick an option in a labelled select of the feed modal.
 *
 * @param page The admin page.
 * @param modal The feed modal.
 * @param label The select's label.
 * @param option The option to pick.
 */
async function pick(page: Page, modal: Locator, label: string, option: string | RegExp): Promise<void> {
	await modal.getByLabel(label).click()
	await page.getByRole('option', { name: option }).first().click()
}

/**
 * Save a feed through the settings section and answer its source id.
 *
 * @param page The admin page.
 * @param name The feed's name.
 * @param catalogue The catalogue's title.
 * @return The OpenRegister source id.
 */
async function saveFeed(page: Page, name: string, catalogue: string): Promise<string> {
	await page.goto(SETTINGS_PAGE, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	const section = page.locator('#section-harvest-feeds')
	await expect(section.getByRole('heading', { name: 'Harvest feeds' })).toBeVisible({ timeout: 30_000 })
	await section.getByRole('button', { name: 'New' }).click()

	const modal = page.getByRole('dialog')
	await modal.getByLabel('Name').fill(name)
	await modal.getByLabel('Source URL').fill(FIXTURE_URL)
	await pick(page, modal, 'Catalogue', catalogue)
	await pick(page, modal, 'Schema', 'publication')
	await pick(page, modal, 'Mapping', 'dcat-dataset-to-publication')

	const saved = page.waitForResponse((r) => r.url().includes(`${APP}/api/harvest/feeds`) && r.request().method() === 'POST')
	await modal.getByRole('button', { name: 'Save' }).click()
	const response = await saved
	expect(response.ok(), `saving the feed: ${response.status()} ${await response.text()}`).toBe(true)
	await expect(modal).toBeHidden()
	const body = await response.json()
	return String(body.id ?? body.source?.id)
}

/**
 * One OpenRegister source.
 *
 * @param request The authenticated request context.
 * @param sourceId The source.
 * @return The source.
 */
async function source(request: APIRequestContext, sourceId: string): Promise<Record<string, any>> {
	const answer = await request.get(`${OR_API}/sources/${sourceId}`)
	expect(answer.ok(), `GET source ${sourceId} -> ${answer.status()}`).toBe(true)
	return answer.json()
}

/**
 * The `@id` of the one dataset in the fixture that has no `dct:title`.
 *
 * @param doc The parsed fixture.
 * @return The dataset's id.
 */
function untitledDatasetId(doc: Record<string, any>): string {
	const found: string[] = []
	const visit = (node: unknown): void => {
		if (node === null || typeof node !== 'object') {
			return
		}
		if (Array.isArray(node)) {
			node.forEach(visit)
			return
		}
		const record = node as Record<string, any>
		const types = ([] as unknown[]).concat(record['@type'] ?? [])
		if (types.some((t) => t === 'dcat:Dataset' || t === 'Dataset') && record['dct:title'] === undefined) {
			found.push(String(record['@id']))
		}
		visit(record['@graph'])
		visit(record['dcat:dataset'])
	}
	visit(doc)
	expect(found, 'the fixture holds exactly one dataset without dct:title').toHaveLength(1)
	return found[0]
}

test.describe('Harvest feed page', () => {
	test.setTimeout(180_000)

	const fx = new Fixtures()
	const sources: string[] = []
	let sourceUuid = ''

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async ({ request }) => {
		if (sourceUuid !== '') {
			const listed = await request.get(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}`, {
				params: { derivedFrom: sourceUuid, _limit: '100' },
			})
			const rows = listed.ok() ? (((await listed.json()).results ?? []) as Array<Record<string, any>>) : []
			for (const row of rows.filter((r) => r.derivedFrom === sourceUuid)) {
				await request.delete(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${row.id ?? row['@self']?.id}`)
			}
		}
		for (const id of sources) {
			await request.delete(`${OR_API}/sources/${id}`)
		}
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('an administrator reads why a run was partial', async ({ page, playwright, request }) => {
		const reader = await playwright.request.newContext()
		let refused: string
		try {
			const fixture = await reader.get(FIXTURE_URL)
			expect(fixture.ok(), `reading the fixture ${FIXTURE_URL} -> ${fixture.status()}`).toBe(true)
			refused = untitledDatasetId(await fixture.json())
		} finally {
			await reader.dispose()
		}

		const run = newRunId()
		const catalogue = await fx.createCatalog('Observed feed', { slug: `${fx.prefix}-observed` })
		const name = `observed-${run}`
		const id = await saveFeed(page, name, catalogue.title)
		sources.push(id)
		sourceUuid = String((await source(request, id)).uuid)

		// GIVEN a feed whose last run refused one dataset.
		const started = await request.post(`${OR_API}/sources/${id}/sync`)
		expect(started.ok(), `starting the run: ${started.status()}`).toBe(true)
		await expect.poll(async () => (await source(request, id)).lastSync ?? null, { timeout: 120_000 }).not.toBeNull()

		// WHEN the administrator opens the feed's page from its card's name.
		await page.goto(SETTINGS_PAGE, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await page.locator('#section-harvest-feeds').getByRole('link', { name }).click()
		await expect(page).toHaveURL(new RegExp(`/settings/harvest/${id}(\\?|$)`), { timeout: 15_000 })
		await expect(page.getByRole('heading', { name }).first()).toBeVisible({ timeout: 30_000 })

		// ... and the last run.
		const runs = page.getByRole('region', { name: 'Runs' })
		const lastRun = runs.getByRole('row').filter({ hasText: /partial/i }).first()
		await expect(lastRun).toBeVisible({ timeout: 30_000 })
		await lastRun.click()

		// THEN the run shows status partial and the refused dataset with its reason.
		const errors = page.getByRole('dialog')
		await expect(errors).toBeVisible()
		await expect(errors).toContainText(/partial/i)
		const entry = errors.getByRole('listitem').filter({ hasText: refused })
		await expect(entry).toBeVisible()
		await expect(entry).toContainText(REASON)
	})
})
