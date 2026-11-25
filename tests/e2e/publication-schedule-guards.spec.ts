/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publication-schedule-guards: an embargo holds until a user in the lift
 * groups lifts it with a reason (REQ-PSG-001, REQ-PSG-002), and publishing a
 * record with no documents takes an explicit confirmation (REQ-PSG-004).
 *
 * WHAT THIS SPEC IS LOOKING FOR, through the API the dialogs call.
 * - A lift by an administrator (the default lift group) with a reason makes a
 *   scheduled publication public at once, answers both dates, and writes one
 *   `publication.embargo.lifted` audit entry with the user and the reason.
 * - A publisher outside the lift groups gets 423 `embargo-holds` from Publish
 *   now and 403 `not-allowed-to-lift` from the lift route, and the publication
 *   stays off the public side.
 * - Publish on a record with no documents answers 409 `no-documents` until the
 *   call carries `confirmNoDocuments: true`.
 *
 * ON THE PAGE (task 2.2). An administrator opens a scheduled publication,
 * sees Lift embargo and no Publish now, gives the reason in the dialog and
 * reads that the publication is public; the publisher outside the lift
 * groups sees neither button on its own scheduled publication.
 *
 * AUTOMATIC DEPUBLICATION (task 3.1). A publication with a depublication date
 * next week carries "until <date>" in the state column of the publications
 * list and "Automatic depublication on <date>" on its page; setting the date
 * in the page's Publication card shows the notice that names it.
 *
 * THE COUNT UP FRONT (task 4.2). Before publishing, `GET visibility` answers
 * how many documents a publish would carry, a document reference counting as
 * one, and the Visibility card shows it beside Publish now.
 *
 * 🔴 THE PUBLISHER IS A REAL ACCOUNT, provisioned over OCS in its own request
 * context with an empty cookie jar, so a refusal is that account's and not the
 * admin's session riding along. It and every publication are removed in
 * afterAll.
 *
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-a-lift-is-logged REQ-PSG-002
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-publishing-with-no-documents-asks-twice REQ-PSG-004
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-automatic-depublication-is-shown REQ-PSG-003
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-a-reference-counts-as-a-document REQ-PSG-004
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-the-officer-is-warned-at-save REQ-PSG-003
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-a-lift-is-logged REQ-PPW-002
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { randomBytes } from 'node:crypto'
import { publicationCatalog } from './publication-catalog.ts'

const APP_BASE = '/index.php/apps/opencatalogi'
const APP_API = `${APP_BASE}/api`
const OBJECTS = '/index.php/apps/openregister/api/objects/publication/publication'
const PUBLISHERS = 'opencatalogi-publishers'
const RUN = Date.now().toString(36)
const NEXT_WEEK = new Date(Date.now() + 7 * 24 * 3600 * 1000).toISOString()

/**
 * The id of a created object, whichever key the answer carries it under.
 *
 * @param body The answer of the create call.
 * @return The id.
 */
function idOf(body: Record<string, unknown>): string {
	const self = (body?.['@self'] ?? {}) as Record<string, unknown>
	return String(body?.id ?? self.id ?? body?.uuid ?? '')
}

test.describe('Publication schedule guards', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	const created: string[] = []
	let publisher: { uid: string, ctx: APIRequestContext }
	let catalogSlug = ''

	/**
	 * Create a publication as the given caller and remember it for cleanup.
	 *
	 * @param ctx The caller.
	 * @param name A name suffix.
	 * @param extra Fields beyond title and summary.
	 * @return The publication id.
	 */
	async function publication(ctx: APIRequestContext, name: string, extra: Record<string, unknown> = {}): Promise<string> {
		const res = await ctx.post(OBJECTS, {
			data: { title: `E2E schedule ${name} ${RUN}`, summary: 'A publication for the schedule-guards check.', ...extra },
		})
		expect(res.ok(), `creating ${name}: ${await res.text()}`).toBe(true)
		const id = idOf(await res.json())
		created.push(id)
		// A publication date only takes effect once the publication is published, and a
		// new one starts as a draft: take it through review to published.
		if ('publicationDate' in extra) {
			for (const status of ['in_review', 'approved', 'published']) {
				const moved = await ctx.patch(`${OBJECTS}/${id}`, { data: { status } })
				expect(moved.ok(), `${name} to ${status}: ${await moved.text()}`).toBe(true)
			}
		}
		return id
	}

	/**
	 * What an anonymous reader gets for the publication in the e2e catalogue.
	 *
	 * @param playwright The Playwright handle.
	 * @param baseURL The instance.
	 * @param id The publication id.
	 * @return The HTTP status.
	 */
	async function anonymousStatus(playwright: { request: { newContext: (o: object) => Promise<APIRequestContext> } }, baseURL: string | undefined, id: string): Promise<number> {
		const anonymous = await playwright.request.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		try {
			return (await anonymous.get(`${APP_API}/${encodeURIComponent(catalogSlug)}/${id}`)).status()
		} finally {
			await anonymous.dispose()
		}
	}

	test.beforeAll(async ({ playwright, baseURL, request }) => {
		catalogSlug = (await publicationCatalog(request, `psg-${RUN}`)).slug

		const uid = `oc-e2e-psg-${RUN}`
		const password = `Oc-${randomBytes(12).toString('hex')}-Pw1!`
		const made = await request.post('/ocs/v2.php/cloud/users?format=json', {
			headers: { 'OCS-APIRequest': 'true' },
			data: { userid: uid, password, groups: [PUBLISHERS] },
		})
		expect(made.ok(), `provisioning ${uid}: ${await made.text()}`).toBe(true)
		publisher = {
			uid,
			ctx: await playwright.request.newContext({
				baseURL,
				storageState: { cookies: [], origins: [] },
				httpCredentials: { username: uid, password, send: 'always' },
				extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			}),
		}
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request.delete(`${OBJECTS}/${id}`).catch(() => undefined)
		}
		if (publisher?.uid) {
			await request
				.delete(`/ocs/v2.php/cloud/users/${publisher.uid}?format=json`, { headers: { 'OCS-APIRequest': 'true' } })
				.catch(() => undefined)
		}
		await publisher?.ctx.dispose()
	})

	test('a lift is logged', async ({ request, playwright, baseURL }) => {
		const id = await publication(request, 'lifted', { publicationDate: NEXT_WEEK })
		expect(await anonymousStatus(playwright, baseURL, id), 'under embargo it is not public').toBe(404)

		const reason = 'Raad heeft het besluit vervroegd'
		const lift = await request.post(`${APP_API}/publications/${id}/lift-embargo`, {
			data: { reason, publicationDate: new Date().toISOString() },
		})
		expect(lift.status(), await lift.text()).toBe(200)
		const answer = await lift.json()
		expect(String(answer.previousPublicationDate ?? '')).not.toBe('')
		expect(String(answer.publicationDate ?? '')).not.toBe('')

		expect(await anonymousStatus(playwright, baseURL, id), 'lifted, it is public').toBe(200)

		const trail = await request.get(`/index.php/apps/openregister/api/audit-trails?object_uuid=${id}`)
		expect(trail.ok(), await trail.text()).toBe(true)
		const entries = ((await trail.json())?.results ?? []) as Array<{ action?: string, changed?: unknown, user?: string }>
		const lifted = entries.filter((entry) => entry.action === 'publication.embargo.lifted')
		expect(lifted, 'one lift entry').toHaveLength(1)
		expect(JSON.stringify(lifted[0])).toContain(reason)
	})

	test('a scheduled publication without the lift right', async ({ playwright, baseURL }) => {
		const id = await publication(publisher.ctx, 'held', { publicationDate: NEXT_WEEK })

		const publishNow = await publisher.ctx.post(`${APP_API}/publications/${id}/publish`, {
			data: { confirmNoDocuments: true },
		})
		expect(publishNow.status(), await publishNow.text()).toBe(423)
		const held = await publishNow.json()
		expect(held.error).toBe('embargo-holds')
		expect(held.property).toBe('publicationDate')
		expect(String(held.embargoUntil ?? '')).not.toBe('')

		const lift = await publisher.ctx.post(`${APP_API}/publications/${id}/lift-embargo`, {
			data: { reason: 'Graag eerder', publicationDate: new Date().toISOString() },
		})
		expect(lift.status(), await lift.text()).toBe(403)
		expect((await lift.json()).error).toBe('not-allowed-to-lift')

		expect(await anonymousStatus(playwright, baseURL, id), 'the embargo still holds').toBe(404)
	})

	test('an administrator lifts the embargo from the publication page', async ({ page, request, playwright, baseURL }) => {
		const id = await publication(request, 'page-lift', { publicationDate: NEXT_WEEK })
		await page.goto(`${APP_BASE}/publications/${encodeURIComponent(catalogSlug)}/${id}`)

		const lift = page.getByTestId('publication-lift-embargo')
		await expect(lift).toBeVisible()
		await expect(page.getByTestId('publication-publish-now')).toHaveCount(0)

		await lift.click()
		const dialog = page.getByTestId('lift-embargo-dialog')
		await expect(dialog).toBeVisible()
		const confirm = page.getByTestId('lift-embargo-confirm')
		await expect(confirm).toBeDisabled()
		await dialog.getByRole('textbox').fill('Raad heeft het besluit vervroegd')
		await confirm.click()

		await expect(page.getByTestId('publication-visibility-message')).toContainText('The embargo was lifted')
		expect(await anonymousStatus(playwright, baseURL, id), 'lifted from the page, it is public').toBe(200)
	})

	test('the page offers no lift and no Publish now without the lift right', async ({ browser, baseURL }) => {
		const id = await publication(publisher.ctx, 'page-held', { publicationDate: NEXT_WEEK })
		const visibility = await publisher.ctx.get(`${APP_API}/publications/${id}/visibility`)
		expect(visibility.ok(), await visibility.text()).toBe(true)
		const answer = await visibility.json()
		expect(answer.state).toBe('scheduled')
		expect(answer.canLiftEmbargo).toBe(false)

		const credentials = await publisher.ctx.storageState()
		const context = await browser.newContext({ baseURL, storageState: credentials })
		try {
			const page = await context.newPage()
			await page.goto(`${APP_BASE}/publications/${encodeURIComponent(catalogSlug)}/${id}`)
			await expect(page.getByTestId('publication-visibility-state')).toBeVisible()
			await expect(page.getByTestId('publication-lift-embargo')).toHaveCount(0)
			await expect(page.getByTestId('publication-publish-now')).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	test('automatic depublication is shown', async ({ page, request }) => {
		const title = `E2E schedule auto-depublication ${RUN}`
		const id = await publication(request, 'auto-depublication', {
			title,
			publicationDate: new Date(Date.now() - 24 * 3600 * 1000).toISOString(),
			depublicationDate: NEXT_WEEK,
		})

		await page.goto(`${APP_BASE}/publications/${encodeURIComponent(catalogSlug)}`)
		const row = page.getByRole('row').filter({ hasText: title })
		await expect(row).toContainText('until')

		await page.goto(`${APP_BASE}/publications/${encodeURIComponent(catalogSlug)}/${id}`)
		await expect(page.getByTestId('publication-visibility-auto-depublication')).toContainText('Automatic depublication on')
	})

	test('the officer is warned at save', async ({ page, request }) => {
		const id = await publication(request, 'warned-at-save', {
			publicationDate: new Date(Date.now() - 24 * 3600 * 1000).toISOString(),
		})
		await page.goto(`${APP_BASE}/publications/${encodeURIComponent(catalogSlug)}/${id}`)
		await expect(page.getByTestId('publication-visibility-state')).toBeVisible()
		await expect(page.getByTestId('publication-visibility-depublication-notice')).toHaveCount(0)

		// Set the date in the Publication card and save it there.
		await page.getByText('Depublication date', { exact: true }).first().click()
		await page.locator('input[type="datetime-local"], input[type="date"]').first().fill(NEXT_WEEK.slice(0, 16))
		await page.getByRole('button', { name: 'Save' }).first().click()

		await expect(page.getByTestId('publication-visibility-depublication-notice'))
			.toContainText('will be taken down automatically on')
	})

	test('publishing with no documents asks twice', async ({ request, playwright, baseURL }) => {
		const id = await publication(request, 'no-documents')

		const first = await request.post(`${APP_API}/publications/${id}/publish`)
		expect(first.status(), await first.text()).toBe(409)
		expect(await first.json()).toEqual({ error: 'no-documents', documents: 0 })
		expect(await anonymousStatus(playwright, baseURL, id), 'refused, it is not public').toBe(404)

		const confirmed = await request.post(`${APP_API}/publications/${id}/publish`, {
			data: { confirmNoDocuments: true },
		})
		expect(confirmed.status(), await confirmed.text()).toBeLessThan(300)
		expect(await anonymousStatus(playwright, baseURL, id), 'confirmed, it is public').toBe(200)
	})

	test('a reference counts as a document', async ({ page, request }) => {
		const id = await publication(request, 'one-reference')
		const ref = await request.post('/index.php/apps/openregister/api/objects/publication/documentReference', {
			data: { title: `E2E reference ${RUN}`, url: 'https://example.org/besluit.pdf', publication: id },
		})
		expect(ref.ok(), await ref.text()).toBe(true)
		const refId = idOf(await ref.json())

		const visibility = await request.get(`${APP_API}/publications/${id}/visibility`)
		expect(visibility.ok(), await visibility.text()).toBe(true)
		expect((await visibility.json()).documents).toBe(1)

		await page.goto(`${APP_BASE}/publications/${encodeURIComponent(catalogSlug)}/${id}`)
		await expect(page.getByTestId('publication-visibility-documents')).toHaveText('Documents to publish: 1')

		await request.delete(`/index.php/apps/openregister/api/objects/publication/documentReference/${refId}`).catch(() => undefined)
	})
})
