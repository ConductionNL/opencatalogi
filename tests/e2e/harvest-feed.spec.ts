/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Harvest feeds: a DCAT JSON-LD feed saved on the settings page, run by hand,
 * landing as draft publications that name their source.
 *
 * WHAT THIS SPEC IS LOOKING FOR. An administrator saves a feed in the
 * "Harvest feeds" section of the admin settings and OpenRegister holds a
 * source with `application` `opencatalogi`; the section shows a card for it
 * with its source and "Never run". A feed on the fixture catalogue is run with
 * "Run now"; afterwards its card shows "Last run" with a time and the three
 * datasets found. The run leaves three publications in the catalogue's schema,
 * none with a `publicationDate` and none returned by the public publications
 * API. Each carries the dataset's `@id` as `source` and the source's uuid as
 * `derivedFrom`, even though the feed's mapping tries to write `source` from
 * the dataset's title, and its detail page says "Harvested from" with the
 * feed's name.
 *
 * DEPENDS ON. The fetcher, `HarvestFeedService`, `HarvestFeedController`
 * (feed routes under `/api/harvest/feeds`), the settings section
 * `#section-harvest-feeds`, `HarvestFeedModal` and the "Harvested from" line of
 * `harvest-feed-intake` (tasks 1.1 to 4.3), the seeded mapping
 * `dcat-dataset-to-publication` (task 2.4), the provenance fragment (task 2.3),
 * and OpenRegister's `openregister/app-harvest-fetchers-and-flow-node` (source
 * routes, `POST /api/sources/{id}/sync`, mappings). The fixture
 * `tests/fixtures/harvest/dcat-catalog.jsonld` (task 3.1) must be served by a
 * test web server the Nextcloud container can reach and OpenRegister's
 * outbound guard allows; `HARVEST_FIXTURE_URL` names it, and the default reads
 * it from the app directory on the instance under test. The section lists one
 * list item per feed, headed by the feed's name. Until all of this is on the
 * instance this spec fails at its first request; it is written now from the
 * change's scenarios so the build that adds them has its acceptance test
 * waiting.
 *
 * WHAT IT LEAVES BEHIND. Nothing: the sources, the copied mapping, the
 * harvested publications, the fixture catalogue and the `harvest-bot` account
 * (when this spec created it) are removed in afterAll.
 *
 * @e2e openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#scenario-a-valid-feed-is-saved
 * @e2e openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#scenario-an-administrator-runs-a-feed-by-hand
 * @e2e openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#scenario-three-datasets-become-three-drafts
 * @e2e openspec/changes/harvest-feed-intake/specs/harvest-feed-intake/spec.md#scenario-the-source-is-on-the-publication
 * @spec openspec/changes/harvest-feed-intake/tasks.md#task-5.1
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { dismissOverlays } from './spec-coverage/_nav.ts'
import { BASE, Fixtures, newRunId, REG_PUBLICATION, SCHEMA_PUBLICATION } from './workflows/_fixtures.ts'

/** OpenRegister's API base path. */
const OR_API = '/index.php/apps/openregister/api'

/** The app's page base path. */
const APP = '/index.php/apps/opencatalogi'

/** The app's API base path. */
const APP_API = `${APP}/api`

/** The admin settings page that holds the "Harvest feeds" section. */
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'

/** Headers for OCS calls. */
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/** The fixture catalogue of task 3.1: three datasets, one of them under `@graph`. */
const FIXTURE_URL = process.env.HARVEST_FIXTURE_URL
	?? `${BASE}/apps/opencatalogi/tests/fixtures/harvest/dcat-catalog.jsonld`

/** The mapping the app seeds (design D6). */
const SEEDED_MAPPING = 'dcat-dataset-to-publication'

/** The acting account the "A valid feed is saved" scenario names. */
const HARVEST_BOT = 'harvest-bot'

/** What a feed form is filled with. */
interface FeedForm {
	name: string
	sourceUrl: string
	catalogue: string
	mapping: string
	schedule?: string
	runAs?: string
}

/**
 * Pick an option in a labelled select of the feed modal.
 *
 * @param page The admin page.
 * @param modal The feed modal.
 * @param label The select's label.
 * @param option The option to pick.
 * @param search Text to type first, for a select that searches.
 */
async function pick(page: Page, modal: Locator, label: string, option: string | RegExp, search?: string): Promise<void> {
	const input = modal.getByLabel(label)
	await input.click()
	if (search !== undefined) {
		await input.fill(search)
	}
	await page.getByRole('option', { name: option }).first().click()
}

/**
 * The "Harvest feeds" section of the settings page.
 *
 * @param page The admin page.
 * @return The section.
 */
function section(page: Page): Locator {
	return page.locator('#section-harvest-feeds')
}

/**
 * The card of one feed in the section.
 *
 * @param page The admin page.
 * @param name The feed's name.
 * @return The card.
 */
function card(page: Page, name: string): Locator {
	return section(page).getByRole('listitem').filter({ has: page.getByRole('heading', { name }) })
}

/**
 * Save a feed through "New" and the feed modal, and answer its source id.
 *
 * @param page The admin page.
 * @param form What to fill in.
 * @return The OpenRegister source id.
 */
async function saveFeed(page: Page, form: FeedForm): Promise<string> {
	await page.goto(SETTINGS_PAGE, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await expect(section(page).getByRole('heading', { name: 'Harvest feeds' })).toBeVisible({ timeout: 30_000 })
	await section(page).getByRole('button', { name: 'New' }).click()

	const modal = page.getByRole('dialog')
	await modal.getByLabel('Name').fill(form.name)
	await modal.getByLabel('Source URL').fill(form.sourceUrl)
	await pick(page, modal, 'Catalogue', form.catalogue)
	await pick(page, modal, 'Schema', 'publication')
	await pick(page, modal, 'Mapping', form.mapping)
	if (form.schedule !== undefined) {
		await modal.getByLabel('Schedule').fill(form.schedule)
	}
	if (form.runAs !== undefined) {
		await pick(page, modal, 'Run as', form.runAs, form.runAs)
	}

	const saved = page.waitForResponse((r) => r.url().includes(`${APP_API}/harvest/feeds`) && r.request().method() === 'POST')
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
 * The publications a source harvested, read back through OpenRegister.
 *
 * @param request The authenticated request context.
 * @param sourceUuid The source's uuid, which the publications carry as `derivedFrom`.
 * @return The publications.
 */
async function harvested(request: APIRequestContext, sourceUuid: string): Promise<Array<Record<string, any>>> {
	const answer = await request.get(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}`, {
		params: { derivedFrom: sourceUuid, _limit: '100' },
	})
	expect(answer.ok(), `listing harvested publications -> ${answer.status()}`).toBe(true)
	const body = await answer.json()
	const rows = (body.results ?? body) as Array<Record<string, any>>
	// Filter here as well: a filter OpenRegister ignores must not let other
	// publications into the count.
	return rows.filter((row) => row.derivedFrom === sourceUuid)
}

/**
 * The `@id` of every `dcat:Dataset` node in a JSON-LD catalogue, found under
 * the catalogue's `dcat:dataset` or in `@graph`, as the fetcher reads it.
 *
 * @param doc The parsed document.
 * @return The dataset ids.
 */
function datasetIds(doc: Record<string, any>): string[] {
	const isDataset = (node: Record<string, any>): boolean => {
		const types = ([] as unknown[]).concat(node['@type'] ?? [])
		return types.some((t) => t === 'dcat:Dataset' || t === 'Dataset' || t === 'http://www.w3.org/ns/dcat#Dataset')
	}
	const nodes: Array<Record<string, any>> = []
	const visit = (node: unknown): void => {
		if (node === null || typeof node !== 'object') {
			return
		}
		if (Array.isArray(node)) {
			node.forEach(visit)
			return
		}
		const record = node as Record<string, any>
		if (isDataset(record)) {
			nodes.push(record)
		}
		visit(record['@graph'])
		visit(record['dcat:dataset'])
	}
	visit(doc)
	return [...new Set(nodes.map((node) => String(node['@id'])).filter((id) => id !== 'undefined'))]
}

test.describe('Harvest feeds', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	const fx = new Fixtures()
	const run = newRunId()
	const sources: string[] = []
	const mappings: string[] = []
	let createdBot = false
	let catalogue = ''
	let catalogueSlug = ''
	let hostileMapping = ''
	let feedName = ''
	let feedId = ''
	let feedUuid = ''

	test.beforeAll(async ({ request }) => {
		await fx.init()
		catalogueSlug = `${fx.prefix}-open-data`
		const created = await fx.createCatalog('Open data', { slug: catalogueSlug })
		catalogue = created.title

		// The acting account the first scenario names.
		const bot = await request.post('/ocs/v2.php/cloud/users', {
			headers: OCS,
			data: { userid: HARVEST_BOT, password: `Harvest-bot-${run}-e2e!` },
		})
		createdBot = bot.ok()

		// A copy of the seeded mapping that also writes `source` from the
		// dataset's title: the GIVEN of "The source is on the publication".
		const list = await request.get(`${OR_API}/mappings`, { params: { _limit: '500' } })
		expect(list.ok(), `listing mappings -> ${list.status()}`).toBe(true)
		const listed = await list.json()
		const seeded = ((listed.results ?? listed) as Array<Record<string, any>>)
			.find((m) => m.slug === SEEDED_MAPPING || m.name === SEEDED_MAPPING || m.reference === SEEDED_MAPPING)
		expect(seeded, `OpenRegister holds the seeded mapping ${SEEDED_MAPPING}`).toBeTruthy()
		hostileMapping = `${SEEDED_MAPPING}-e2e-${run}`
		const copy = await request.post(`${OR_API}/mappings`, {
			data: {
				name: hostileMapping,
				mapping: { ...(seeded?.mapping ?? {}), source: seeded?.mapping?.title ?? '{{ dct:title }}' },
			},
		})
		expect(copy.ok(), `copying the mapping: ${copy.status()} ${await copy.text()}`).toBe(true)
		mappings.push(String((await copy.json()).id))
	})

	test.afterAll(async ({ request }) => {
		if (feedUuid !== '') {
			for (const publication of await harvested(request, feedUuid).catch(() => [])) {
				await request.delete(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${publication.id ?? publication['@self']?.id}`)
			}
		}
		for (const id of sources) {
			await request.delete(`${OR_API}/sources/${id}`)
		}
		for (const id of mappings) {
			await request.delete(`${OR_API}/mappings/${id}`)
		}
		if (createdBot) {
			await request.delete(`/ocs/v2.php/cloud/users/${HARVEST_BOT}`, { headers: OCS })
		}
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('a valid feed is saved as an OpenCatalogi source and gets a card', async ({ page, request }) => {
		const name = `valid-${run}`
		const id = await saveFeed(page, {
			name,
			sourceUrl: 'https://data.example.nl/catalog.jsonld',
			catalogue,
			mapping: SEEDED_MAPPING,
			schedule: '15 3 * * *',
			runAs: HARVEST_BOT,
		})
		sources.push(id)

		const saved = await source(request, id)
		expect(saved.application).toBe('opencatalogi')
		expect(saved.type).toBe('opencatalogi.dcat-jsonld')
		expect(saved.schedule).toBe('15 3 * * *')
		expect(saved.runAs).toBe(HARVEST_BOT)

		const feed = card(page, name)
		await expect(feed).toBeVisible()
		await expect(feed).toContainText('https://data.example.nl/catalog.jsonld')
		await expect(feed).toContainText('Never run')
	})

	test('an administrator runs a feed by hand and the card shows the run', async ({ page, request }) => {
		feedName = `fixture-${run}`
		feedId = await saveFeed(page, { name: feedName, sourceUrl: FIXTURE_URL, catalogue, mapping: hostileMapping })
		sources.push(feedId)
		feedUuid = String((await source(request, feedId)).uuid)

		const feed = card(page, feedName)
		await expect(feed).toContainText('Never run')

		const started = page.waitForResponse((r) => r.url().includes(`${OR_API}/sources/${feedId}/sync`) && r.request().method() === 'POST')
		await feed.getByRole('button', { name: 'Run now' }).click()
		const answer = await started
		expect(answer.ok(), `Run now: ${answer.status()} ${await answer.text()}`).toBe(true)

		await expect.poll(async () => (await source(request, feedId)).lastSync ?? null, { timeout: 120_000 }).not.toBeNull()

		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		const after = card(page, feedName)
		await expect(after).toContainText('Last run', { timeout: 30_000 })
		await expect(after).not.toContainText('Never run')
		await expect(after).toContainText(/\d{1,2}:\d{2}/)
		await expect(after).toContainText(/\b3\b/)
	})

	test('three datasets become three drafts the public API does not return', async ({ playwright, request }) => {
		expect(feedUuid, 'the previous test ran the fixture feed').not.toBe('')
		const drafts = await harvested(request, feedUuid)
		expect(drafts).toHaveLength(3)
		for (const draft of drafts) {
			expect(draft.publicationDate ?? null, `${draft.title} has no publicationDate`).toBeNull()
		}

		const anonymous = await playwright.request.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: { Accept: 'application/json' },
		})
		try {
			const listed = await anonymous.get(`${APP_API}/${catalogueSlug}`, { params: { _limit: '100' } })
			const publicIds = listed.ok()
				? (((await listed.json()).results ?? []) as Array<Record<string, any>>).map((row) => String(row.id ?? row['@self']?.id))
				: []
			for (const draft of drafts) {
				const id = String(draft.id ?? draft['@self']?.id)
				expect(publicIds, `the public list holds no ${id}`).not.toContain(id)
				const shown = await anonymous.get(`${APP_API}/${catalogueSlug}/${id}`)
				expect(shown.ok(), `the public API returns no ${id}`).toBe(false)
			}
		} finally {
			await anonymous.dispose()
		}
	})

	test('the source is on the publication and its page says where it came from', async ({ page, playwright, request }) => {
		expect(feedUuid, 'the previous tests ran the fixture feed').not.toBe('')
		const reader = await playwright.request.newContext()
		let expected: string[]
		try {
			const fixture = await reader.get(FIXTURE_URL)
			expect(fixture.ok(), `reading the fixture ${FIXTURE_URL} -> ${fixture.status()}`).toBe(true)
			expected = datasetIds(await fixture.json())
		} finally {
			await reader.dispose()
		}
		expect(expected).toHaveLength(3)

		// The mapping wrote `source` from the title; OpenRegister's provenance
		// step must have replaced it with the dataset's `@id`.
		const drafts = await harvested(request, feedUuid)
		expect(drafts.map((draft) => String(draft.source)).sort()).toEqual([...expected].sort())
		for (const draft of drafts) {
			expect(draft.derivedFrom).toBe(feedUuid)
		}

		const first = drafts[0]
		await page.goto(`${APP}/publications/${catalogueSlug}/${first.id ?? first['@self']?.id}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.getByText(String(first.title)).first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(new RegExp(`Harvested from\\s*${feedName}`)).first()).toBeVisible()
		await expect(page.getByRole('link', { name: feedName })).toBeVisible()
	})
})
