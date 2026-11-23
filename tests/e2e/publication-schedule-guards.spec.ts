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
 * NOT HERE YET. The dialogs themselves (the Lift embargo dialog of task 2.2,
 * the hidden Publish now, the count and the confirmation tick of task 4.2) are
 * not built; their clicks join these tests when they are.
 *
 * 🔴 THE PUBLISHER IS A REAL ACCOUNT, provisioned over OCS in its own request
 * context with an empty cookie jar, so a refusal is that account's and not the
 * admin's session riding along. It and every publication are removed in
 * afterAll.
 *
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-a-lift-is-logged REQ-PSG-002
 * @e2e openspec/changes/publication-schedule-guards/specs/publications/spec.md#scenario-publishing-with-no-documents-asks-twice REQ-PSG-004
 * @spec openspec/changes/publication-schedule-guards/specs/publications/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { publicationCatalog } from './publication-catalog.ts'

const APP_API = '/index.php/apps/opencatalogi/api'
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
})
