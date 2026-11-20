/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Harvest conflicts: a policy per feed, the review queue and a field-by-field merge.
 *
 * WHAT THIS SPEC IS LOOKING FOR. An administrator picks a policy in the feed
 * modal and OpenRegister stores the matching `conflictStrategy`. A harvested
 * dataset that collides with a locally edited publication waits on the
 * "Harvest review" page. An editor takes the harvested title, keeps the local
 * summary and saves the merge; the publication carries exactly that and the
 * item leaves the queue. A bulk "Keep ours" over two items empties the queue
 * and leaves both publications as they were.
 *
 * DEPENDS ON. OpenRegister's sync-record routes
 * (`openregister/app-harvest-fetchers-and-flow-node` tasks 5.1 and 5.2) and the
 * feed modal of `harvest-feed-intake`. Until both are on the instance this spec
 * fails at its first request; it is written now from the change's scenarios so
 * the build that adds them has its acceptance test waiting.
 *
 * @e2e openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md#scenario-an-administrator-picks-a-policy
 * @e2e openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md#scenario-an-editor-merges-one-field
 * @e2e openspec/changes/harvest-conflict-policies/specs/harvest-conflict-policies/spec.md#scenario-a-bulk-keep-ours-over-two-items
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { BASE, Fixtures, newRunId, REG_PUBLICATION } from './workflows/_fixtures.ts'

/** OpenRegister's API base path. */
const OR_API = '/index.php/apps/openregister/api'

/** The app's page base path. */
const APP = '/index.php/apps/opencatalogi'

/**
 * Create a harvest feed (an OpenRegister source) through the feed modal and
 * answer its id.
 *
 * @param page The admin page.
 * @param name The feed's name.
 * @param policy The policy label to pick.
 * @return The source id.
 */
async function createFeedWithPolicy(page: Page, name: string, policy: string): Promise<string> {
	await page.goto(`${BASE}${APP}/harvest`)
	await page.getByRole('button', { name: 'Add feed' }).click()
	const modal = page.getByRole('dialog')
	await modal.getByLabel('Name').fill(name)
	await modal.getByLabel('Feed URL').fill(`${BASE}${APP}/api/catalogi/publications/dcat`)
	await modal.getByRole('radio', { name: policy }).check()
	const saved = page.waitForResponse((r) => r.url().includes(`${OR_API}/sources`) && r.request().method() !== 'GET')
	await modal.getByRole('button', { name: 'Save' }).click()
	const response = await saved
	expect(response.ok()).toBe(true)
	return String((await response.json()).id)
}

/**
 * Run a source once and wait until OpenRegister reports the run finished.
 *
 * @param request The authenticated request context.
 * @param sourceId The source.
 */
async function runOnce(request: APIRequestContext, sourceId: string): Promise<void> {
	const started = await request.post(`${OR_API}/sources/${sourceId}/sync`)
	expect(started.ok()).toBe(true)
	await expect.poll(async () => {
		const runs = await request.get(`${OR_API}/sources/${sourceId}`)
		return (await runs.json()).lastSync ?? null
	}, { timeout: 60_000 }).not.toBeNull()
}

/**
 * The conflict records of a source.
 *
 * @param request The authenticated request context.
 * @param sourceId The source.
 * @return The records.
 */
async function conflicts(request: APIRequestContext, sourceId: string): Promise<Array<Record<string, unknown>>> {
	const answer = await request.get(`${OR_API}/sources/${sourceId}/sync-records?status=conflict`)
	expect(answer.ok()).toBe(true)
	const body = await answer.json()
	return body.results ?? body
}

test.describe('Harvest conflicts', () => {
	const fx = new Fixtures()
	const sources: string[] = []

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async ({ request }) => {
		for (const id of sources) {
			await request.delete(`${OR_API}/sources/${id}`)
		}
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('an administrator picks a policy and OpenRegister stores its strategy', async ({ page, request }) => {
		const id = await createFeedWithPolicy(page, `policy-${newRunId()}`, 'Keep ours, store theirs aside')
		sources.push(id)

		const source = await (await request.get(`${OR_API}/sources/${id}`)).json()
		expect(source.conflictStrategy).toBe('local-wins')
		expect(source.conflictRules ?? null).toBeNull()
	})

	test('an editor merges one field and the item leaves the queue', async ({ page, request }) => {
		const run = newRunId()
		const id = await createFeedWithPolicy(page, `merge-${run}`, 'Ask a person')
		sources.push(id)

		// First run links the dataset to a publication; a local edit, then a re-run, collides.
		const publication = await fx.createPublication(`merge-${run}`, { summary: 'Harvested summary' }, REG_PUBLICATION)
		await runOnce(request, id)
		const local = await request.patch(`${OR_API}/objects/${REG_PUBLICATION}/${publication.schema}/${publication.id}`, {
			data: { title: `Local title ${run}`, summary: 'Local summary' },
		})
		expect(local.ok()).toBe(true)
		await runOnce(request, id)
		expect((await conflicts(request, id)).length).toBeGreaterThan(0)

		await page.goto(`${BASE}${APP}/harvest/review`)
		await page.getByRole('row', { name: new RegExp(`Local title ${run}`) }).getByRole('button', { name: 'Decide' }).click()
		const modal = page.getByRole('dialog')
		await modal.getByLabel('Keep for title').click()
		await page.getByRole('option', { name: 'Harvested' }).click()
		await modal.getByLabel('Keep for summary').click()
		await page.getByRole('option', { name: 'Ours' }).click()
		await modal.getByRole('button', { name: 'Save the merge' }).click()
		await expect(modal).toBeHidden()

		const merged = await (await request.get(`${OR_API}/objects/${REG_PUBLICATION}/${publication.schema}/${publication.id}`)).json()
		expect(merged.title).not.toBe(`Local title ${run}`)
		expect(merged.summary).toBe('Local summary')
		expect(await conflicts(request, id)).toHaveLength(0)
	})

	test('a bulk keep-ours over two items empties the queue', async ({ page, request }) => {
		const run = newRunId()
		const id = await createFeedWithPolicy(page, `bulk-${run}`, 'Ask a person')
		sources.push(id)

		const first = await fx.createPublication(`bulk-a-${run}`, {}, REG_PUBLICATION)
		const second = await fx.createPublication(`bulk-b-${run}`, {}, REG_PUBLICATION)
		await runOnce(request, id)
		for (const publication of [first, second]) {
			await request.patch(`${OR_API}/objects/${REG_PUBLICATION}/${publication.schema}/${publication.id}`, {
				data: { title: `Ours ${publication.id}` },
			})
		}
		await runOnce(request, id)
		expect((await conflicts(request, id)).length).toBeGreaterThanOrEqual(2)

		await page.goto(`${BASE}${APP}/harvest/review`)
		for (const publication of [first, second]) {
			await page.getByRole('row', { name: new RegExp(`Ours ${publication.id}`) }).getByRole('checkbox').check()
		}
		await page.getByRole('button', { name: 'Keep ours' }).click()
		await expect(page.getByText('Nothing waits for a decision.')).toBeVisible()

		for (const publication of [first, second]) {
			const kept = await (await request.get(`${OR_API}/objects/${REG_PUBLICATION}/${publication.schema}/${publication.id}`)).json()
			expect(kept.title).toBe(`Ours ${publication.id}`)
		}
	})
})
