/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operations-publish-rights 2.1 and 3.1: only members of
 * `opencatalogi-publishers` (and administrators) change whether a publication
 * is public; an editor outside the group edits everything else, is not offered
 * the publish actions and is refused by the publish endpoints.
 *
 * 🔴 EACH PRINCIPAL IS A REAL ACCOUNT. The editor and the publisher are two
 * Nextcloud accounts this suite provisions over OCS, each in its own request
 * context with an empty cookie jar, so a refusal is the editor's and not a
 * missing login, and an allowed call is the publisher's and not the admin's
 * session riding along.
 *
 * WHAT IT CREATES AND REMOVES. The two accounts (the publisher in
 * `opencatalogi-publishers`) and one draft publication each of them creates.
 * The publications are deleted and the accounts removed in afterAll.
 *
 * @e2e openspec/changes/operations-publish-rights/specs/publications/spec.md#scenario-an-editor-tries-to-publish
 * @e2e openspec/changes/operations-publish-rights/specs/publications/spec.md#scenario-an-editor-edits-a-title
 * @e2e openspec/changes/operations-publish-rights/specs/publications/spec.md#scenario-an-editor-opens-a-draft
 * @e2e openspec/changes/operations-publish-rights/specs/publications/spec.md#scenario-a-direct-call-by-an-editor
 * @spec openspec/changes/operations-publish-rights/specs/publications/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'

const APP_API = '/index.php/apps/opencatalogi/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication/publication'
const PUBLISHERS = 'opencatalogi-publishers'
const RUN = Date.now().toString(36)

interface Principal {
	uid: string
	password: string
	ctx: APIRequestContext
	publicationId: string
}

test.describe('Publish rights', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	const principals: Record<'editor' | 'publisher', Principal> = {} as never

	test.beforeAll(async ({ playwright, baseURL, request }) => {
		for (const role of ['editor', 'publisher'] as const) {
			const uid = `oc-e2e-${role}-${RUN}`
			const password = `Oc-${randomBytes(12).toString('hex')}-Pw1!`
			const groups = role === 'publisher' ? [PUBLISHERS] : []
			const made = await request.post('/ocs/v2.php/cloud/users?format=json', {
				headers: { 'OCS-APIRequest': 'true' },
				data: { userid: uid, password, groups },
			})
			expect(made.ok(), `provisioning ${uid}: ${await made.text()}`).toBe(true)

			const ctx = await playwright.request.newContext({
				baseURL,
				storageState: { cookies: [], origins: [] },
				httpCredentials: { username: uid, password, send: 'always' },
				extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			})
			const created = await ctx.post(OBJECTS, {
				data: { title: `E2E ${role} draft ${RUN}`, summary: 'A draft for the publish-rights check.' },
			})
			expect(created.ok(), `${role} creates a draft: ${await created.text()}`).toBe(true)
			const body = await created.json()
			principals[role] = { uid, password, ctx, publicationId: String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '') }
		}
	})

	test.afterAll(async ({ request }) => {
		for (const principal of Object.values(principals)) {
			if (principal?.publicationId) {
				await request.delete(`${OBJECTS}/${principal.publicationId}`).catch(() => undefined)
			}
			if (principal?.uid) {
				await request
					.delete(`/ocs/v2.php/cloud/users/${principal.uid}?format=json`, { headers: { 'OCS-APIRequest': 'true' } })
					.catch(() => undefined)
			}
			await principal?.ctx.dispose()
		}
	})

	test('the editor is not offered the publish actions, the publisher is', async () => {
		const editor = principals.editor
		const forEditor = await editor.ctx.get(`${APP_API}/publications/${editor.publicationId}/visibility`)
		expect(forEditor.ok(), await forEditor.text()).toBe(true)
		expect((await forEditor.json()).canPublish, 'an editor outside the group').toBe(false)

		const publisher = principals.publisher
		const forPublisher = await publisher.ctx.get(`${APP_API}/publications/${publisher.publicationId}/visibility`)
		expect(forPublisher.ok(), await forPublisher.text()).toBe(true)
		expect((await forPublisher.json()).canPublish, 'a member of the group').toBe(true)
	})

	test('the publish endpoints refuse the editor and name the group', async () => {
		const { ctx, publicationId } = principals.editor
		for (const action of ['publish', 'withdraw']) {
			const res = await ctx.post(`${APP_API}/publications/${publicationId}/${action}`)
			expect(res.status(), `${action}: ${await res.text()}`).toBe(403)
			const body = await res.json()
			expect(body.error).toBe('not-a-publisher')
			expect(JSON.stringify(body)).toContain(PUBLISHERS)
		}
	})

	test('the editor may change a title but not whether the publication is public', async () => {
		const { ctx, publicationId } = principals.editor
		const title = await ctx.patch(`${OBJECTS}/${publicationId}`, { data: { title: `E2E editor renamed ${RUN}` } })
		expect(title.ok(), `a title change: ${await title.text()}`).toBe(true)

		const publish = await ctx.patch(`${OBJECTS}/${publicationId}`, { data: { publicationDate: new Date().toISOString() } })
		expect(publish.ok(), 'a publicationDate change by an editor is refused').toBe(false)
		expect(await publish.text(), 'the refusal names the property').toContain('publicationDate')

		const visibility = await ctx.get(`${APP_API}/publications/${publicationId}/visibility`)
		expect(JSON.stringify(await visibility.json()), 'the publication is still not public').not.toMatch(/"state":\s*"public"/)
	})

	test('the publisher publishes her own draft', async () => {
		const { ctx, publicationId } = principals.publisher
		const res = await ctx.post(`${APP_API}/publications/${publicationId}/publish`)
		expect(res.status(), await res.text()).toBeLessThan(300)
	})

	test('on the page the editor sees no Publish now', async ({ browser, request, baseURL }) => {
		const catalog = await publicationCatalog(request, `pr-${RUN}`)
		const scope = await publicationScope(request)
		const editor = principals.editor
		const context = await browser.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: editor.uid, password: editor.password, send: 'always' },
		})
		const page = await context.newPage()
		await page.goto(`/index.php/apps/opencatalogi/publications/${catalog.slug}/${scope.register}/${scope.schema}/${editor.publicationId}`)
		await expect(page.getByTestId('publication-visibility')).toBeVisible({ timeout: 45_000 })
		await expect(page.getByTestId('publication-publish-now'), 'no Publish now for an editor').toHaveCount(0)
		await context.close()
	})
})
