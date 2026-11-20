/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The publication lifecycle on OpenRegister: one state the server computes,
 * publish now as a named transition, and a public publication that is unlisted
 * and put back.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The list endpoint carries the same `state` per
 * row as the page's visibility answer, and the list shows its label. Publish
 * now on an approved publication moves it to published with a release moment.
 * An unlisted publication is missing from anonymous search but still answers at
 * its own link; List again puts it back.
 *
 * The base URL comes from NEXTCLOUD_URL (base-url.ts); anonymous calls run in a
 * context with an empty storage state.
 *
 * @e2e openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#scenario-one-state-on-the-list-and-the-page REQ-PLC-002
 * @e2e openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#scenario-publish-now-moves-the-lifecycle REQ-PLC-003
 * @e2e openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#scenario-unlisted-still-reachable REQ-PLC-006
 * @e2e openspec/changes/publication-lifecycle-on-or/specs/publications/spec.md#scenario-put-back REQ-PLC-006
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog } from './publication-catalog.ts'
import { BASE, Fixtures, REG_PUBLICATION } from './workflows/_fixtures.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/** A moment relative to now, in days. */
function daysFromNow(days: number): string {
	return new Date(Date.now() + days * 86400000).toISOString()
}

/**
 * Whether an anonymous search for a title finds the publication.
 *
 * @param anonymous A request context without a session.
 * @param title The title searched for.
 * @param id The publication id.
 */
async function anonymousSearchFinds(anonymous: APIRequestContext, title: string, id: string): Promise<boolean> {
	const answer = await anonymous.post(`${API_BASE}/publications/search`, { data: { _search: title } })
	expect(answer.ok(), 'anonymous search must answer').toBe(true)
	const body = await answer.json()
	const rows: Array<Record<string, unknown>> = body.results ?? []

	return rows.some((row) => String(row.id ?? (row['@self'] as Record<string, unknown> | undefined)?.id ?? '') === id)
}

test.describe('Publication lifecycle', () => {
	const fx = new Fixtures()

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('one state on the list and the page', async ({ request: admin, page }) => {
		const catalog = await publicationCatalog(admin, 'e2e-lifecycle')
		const seeded = {
			draft: await fx.createPublication('state-draft', { status: 'draft' }, REG_PUBLICATION),
			in_review: await fx.createPublication('state-review', { status: 'in_review' }, REG_PUBLICATION),
			scheduled: await fx.createPublication('state-scheduled', { status: 'published', publicationDate: daysFromNow(7) }, REG_PUBLICATION),
			public: await fx.createPublication('state-public', { status: 'published', publicationDate: daysFromNow(-7) }, REG_PUBLICATION),
			withdrawn: await fx.createPublication(
				'state-withdrawn',
				{ status: 'published', publicationDate: daysFromNow(-30), depublicationDate: daysFromNow(-1) },
				REG_PUBLICATION,
			),
		}

		const list = await admin.get(`${API_BASE}/${encodeURIComponent(catalog.slug)}`, { params: { _limit: '200' } })
		expect(list.ok()).toBe(true)
		const rows: Array<Record<string, unknown>> = (await list.json()).results ?? []

		for (const [state, publication] of Object.entries(seeded)) {
			const row = rows.find((candidate) => String(candidate.id ?? '') === publication.id)
			expect(row, `the list carries ${state}`).toBeTruthy()
			expect(row?.state).toBe(state)

			const visibility = await admin.get(`${API_BASE}/publications/${publication.id}/visibility`)
			expect((await visibility.json()).state, `the page of ${state} shows the same state`).toBe(state)
		}

		await page.goto(`${BASE}/index.php/apps/opencatalogi${catalog.path}`)
		for (const label of ['Draft', 'In review', 'Scheduled', 'Public', 'Withdrawn']) {
			await expect(page.getByText(label, { exact: true }).first()).toBeVisible()
		}
	})

	test('publish now moves the lifecycle', async ({ request: admin }) => {
		const publication = await fx.createPublication('publish-now', { status: 'approved' }, REG_PUBLICATION)

		const published = await admin.post(`${API_BASE}/publications/${publication.id}/publish`)
		expect(published.ok()).toBe(true)
		expect((await published.json()).state).toBe('public')

		const visibility = await admin.get(`${API_BASE}/publications/${publication.id}/visibility`)
		expect((await visibility.json()).state).toBe('public')

		const stored = await admin.get(`/index.php/apps/openregister/api/objects/${publication.id}`)
		const body = await stored.json()
		expect(body.status).toBe('published')
		expect(body.publicationDate).toBeTruthy()
		expect(body.firstReleasedAt).toBeTruthy()
	})

	test('unlisted, still reachable, and put back', async ({ request: admin, playwright }) => {
		const catalog = await publicationCatalog(admin, 'e2e-lifecycle')
		const publication = await fx.createPublication(
			'unlisted',
			{ status: 'published', publicationDate: daysFromNow(-3) },
			REG_PUBLICATION,
		)
		const anonymous = await playwright.request.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
		})

		try {
			expect(await anonymousSearchFinds(anonymous, publication.title, publication.id)).toBe(true)

			const unlisted = await admin.post(`${API_BASE}/publications/${publication.id}/unlist`)
			expect(unlisted.ok()).toBe(true)
			expect((await unlisted.json()).unlisted).toBe(true)

			expect(await anonymousSearchFinds(anonymous, publication.title, publication.id), 'search does not find it').toBe(false)
			const direct = await anonymous.get(`${API_BASE}/${encodeURIComponent(catalog.slug)}/${publication.id}`)
			expect(direct.status(), 'its public link still shows it').toBe(200)

			const listed = await admin.post(`${API_BASE}/publications/${publication.id}/list`)
			expect(listed.ok()).toBe(true)
			expect((await listed.json()).unlisted).toBe(false)
			expect(await anonymousSearchFinds(anonymous, publication.title, publication.id), 'search finds it again').toBe(true)
		} finally {
			await anonymous.dispose()
		}
	})
})
