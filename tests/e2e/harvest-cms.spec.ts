/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Harvest protocols: the feed form offers every OpenCatalogi source type, and
 * a WordPress site feeds draft publications.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The feed modal's "Type" list holds exactly the
 * OpenCatalogi types OpenRegister's `GET /api/sources/types` lists: the six of
 * `harvest-protocol-plugins` beside `opencatalogi.dcat-jsonld`, each by its
 * display name. An administrator then saves an `opencatalogi.wordpress-rest`
 * feed against a recorded WordPress REST fixture and runs it; every post the
 * fixture serves becomes a draft publication (no `publicationDate`) that
 * carries the post's `link` as its source.
 *
 * DEPENDS ON. The feed section, the feed modal and `HarvestFeedService` of
 * `harvest-feed-intake`, this change's six fetchers registered on
 * `RegisterSourceFetchersEvent` (task 1.1), `WordpressRestFetcher` (task 1.6),
 * the seeded mapping `wordpress-post-to-publication` (task 2.2) and the type
 * list in the form (task 2.3); OpenRegister's
 * `openregister/app-harvest-fetchers-and-flow-node` (source types, source
 * routes, `POST /api/sources/{id}/sync`). The WordPress fixture must be served
 * by a test web server the Nextcloud container can reach and OpenRegister's
 * outbound guard allows, answering `/wp-json/wp/v2/posts` and
 * `/wp-json/wp/v2/pages` with an `X-WP-TotalPages` header;
 * `HARVEST_WORDPRESS_FIXTURE_URL` names its root, and the default is
 * `tests/fixtures/harvest/wordpress` in the app directory on the instance under
 * test. The modal's type select is labelled "Type". Until all of this is on the
 * instance this spec fails at its first request; it is written now from the
 * change's scenarios so the build that adds them has its acceptance test
 * waiting.
 *
 * NOTE. Task 2.3 asks this file to carry `@e2e` for "The six types are
 * offered", but the spec marks that scenario `@e2e exclude` (registry
 * contract). The exclusion stands until the spec is changed, so this file
 * carries no `@e2e` line for it; the test below still checks the form half,
 * which is only visible in a browser.
 *
 * WHAT IT LEAVES BEHIND. Nothing: the source, the harvested publications and
 * the fixture catalogue are removed in afterAll.
 *
 * @spec openspec/changes/harvest-protocol-plugins/tasks.md#task-2.3
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

/** The root of the recorded WordPress site. */
const WORDPRESS_URL = process.env.HARVEST_WORDPRESS_FIXTURE_URL
	?? `${BASE}/apps/opencatalogi/tests/fixtures/harvest/wordpress`

/** Every OpenCatalogi source type after this change. */
const OPENCATALOGI_TYPES = [
	'opencatalogi.dcat-jsonld',
	'opencatalogi.dcat-rdf',
	'opencatalogi.oai-pmh',
	'opencatalogi.ckan-api',
	'opencatalogi.schema-org-dataset',
	'opencatalogi.website-sitemap',
	'opencatalogi.wordpress-rest',
]

/** A source type as OpenRegister lists it. */
interface SourceType {
	type: string
	displayName: string
	application: string
}

/**
 * OpenRegister's source types.
 *
 * @param request The authenticated request context.
 * @return The types.
 */
async function sourceTypes(request: APIRequestContext): Promise<SourceType[]> {
	const answer = await request.get(`${OR_API}/sources/types`)
	expect(answer.ok(), `GET sources/types -> ${answer.status()}`).toBe(true)
	const body = await answer.json()
	return ((body.results ?? body) as Array<Record<string, any>>).map((row) => ({
		type: String(row.type ?? row.id),
		displayName: String(row.displayName ?? row.label ?? row.name),
		application: String(row.application ?? row.app ?? ''),
	}))
}

/**
 * Open the feed modal from the settings section.
 *
 * @param page The admin page.
 * @return The modal.
 */
async function openFeedModal(page: Page): Promise<Locator> {
	await page.goto(SETTINGS_PAGE, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	const section = page.locator('#section-harvest-feeds')
	await expect(section.getByRole('heading', { name: 'Harvest feeds' })).toBeVisible({ timeout: 30_000 })
	await section.getByRole('button', { name: 'New' }).click()
	const modal = page.getByRole('dialog')
	await expect(modal).toBeVisible()
	return modal
}

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
 * The publications a source harvested.
 *
 * @param request The authenticated request context.
 * @param sourceUuid The source's uuid, carried as `derivedFrom`.
 * @return The publications.
 */
async function harvested(request: APIRequestContext, sourceUuid: string): Promise<Array<Record<string, any>>> {
	const answer = await request.get(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}`, {
		params: { derivedFrom: sourceUuid, _limit: '100' },
	})
	expect(answer.ok(), `listing harvested publications -> ${answer.status()}`).toBe(true)
	const rows = ((await answer.json()).results ?? []) as Array<Record<string, any>>
	return rows.filter((row) => row.derivedFrom === sourceUuid)
}

test.describe('Harvest protocols', () => {
	test.setTimeout(180_000)

	const fx = new Fixtures()
	const sources: string[] = []
	const uuids: string[] = []

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async ({ request }) => {
		for (const uuid of uuids) {
			for (const row of await harvested(request, uuid).catch(() => [])) {
				await request.delete(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${row.id ?? row['@self']?.id}`)
			}
		}
		for (const id of sources) {
			await request.delete(`${OR_API}/sources/${id}`)
		}
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('the six types are offered beside DCAT JSON-LD', async ({ page, request }) => {
		const ours = (await sourceTypes(request)).filter((t) => t.type.startsWith('opencatalogi.'))
		expect(ours.map((t) => t.type).sort()).toEqual([...OPENCATALOGI_TYPES].sort())
		for (const t of ours) {
			expect(t.application, `${t.type} is owned by opencatalogi`).toBe('opencatalogi')
		}

		const modal = await openFeedModal(page)
		await modal.getByLabel('Type').click()
		const options = page.getByRole('option')
		for (const t of ours) {
			await expect(options.filter({ hasText: t.displayName }), `the form offers ${t.displayName}`).toHaveCount(1)
		}
		// Exactly OpenRegister's OpenCatalogi types: nothing of another app's, nothing extra.
		await expect(options).toHaveCount(ours.length)
	})

	test('a wordpress-rest feed on the recorded site leaves draft publications keyed by the post link', async ({ page, playwright, request }) => {
		const wordpress = (await sourceTypes(request)).find((t) => t.type === 'opencatalogi.wordpress-rest')
		expect(wordpress, 'OpenRegister lists opencatalogi.wordpress-rest').toBeTruthy()

		// What the recorded site serves, read the way the fetcher reads it.
		const reader = await playwright.request.newContext()
		let links: string[]
		try {
			const posts = await reader.get(`${WORDPRESS_URL}/wp-json/wp/v2/posts`)
			expect(posts.ok(), `reading the fixture posts -> ${posts.status()}`).toBe(true)
			links = ((await posts.json()) as Array<Record<string, any>>).map((post) => String(post.link))
			const pages = await reader.get(`${WORDPRESS_URL}/wp-json/wp/v2/pages`)
			if (pages.ok()) {
				links.push(...((await pages.json()) as Array<Record<string, any>>).map((item) => String(item.link)))
			}
		} finally {
			await reader.dispose()
		}
		expect(links.length, 'the fixture serves posts').toBeGreaterThan(0)

		const run = newRunId()
		const catalogue = await fx.createCatalog('WordPress site', { slug: `${fx.prefix}-wordpress` })
		const name = `wordpress-${run}`
		const modal = await openFeedModal(page)
		await pick(page, modal, 'Type', String(wordpress?.displayName))
		await modal.getByLabel('Name').fill(name)
		await modal.getByLabel('Source URL').fill(WORDPRESS_URL)
		await pick(page, modal, 'Catalogue', catalogue.title)
		await pick(page, modal, 'Schema', 'publication')
		await pick(page, modal, 'Mapping', 'wordpress-post-to-publication')
		const saved = page.waitForResponse((r) => r.url().includes(`${APP}/api/harvest/feeds`) && r.request().method() === 'POST')
		await modal.getByRole('button', { name: 'Save' }).click()
		const response = await saved
		expect(response.ok(), `saving the feed: ${response.status()} ${await response.text()}`).toBe(true)
		const body = await response.json()
		const id = String(body.id ?? body.source?.id)
		sources.push(id)

		const stored = await source(request, id)
		expect(stored.type).toBe('opencatalogi.wordpress-rest')
		expect(stored.application).toBe('opencatalogi')
		uuids.push(String(stored.uuid))

		const card = page.locator('#section-harvest-feeds').getByRole('listitem').filter({ has: page.getByRole('heading', { name }) })
		const started = page.waitForResponse((r) => r.url().includes(`${OR_API}/sources/${id}/sync`) && r.request().method() === 'POST')
		await card.getByRole('button', { name: 'Run now' }).click()
		expect((await started).ok()).toBe(true)
		await expect.poll(async () => (await source(request, id)).lastSync ?? null, { timeout: 120_000 }).not.toBeNull()

		const drafts = await harvested(request, String(stored.uuid))
		expect(drafts.map((draft) => String(draft.source)).sort()).toEqual([...links].sort())
		for (const draft of drafts) {
			expect(draft.publicationDate ?? null, `${draft.title} has no publicationDate`).toBeNull()
		}
	})
})
