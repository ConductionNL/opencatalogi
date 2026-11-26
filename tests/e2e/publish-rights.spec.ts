/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operations-publish-rights 2.1 and 3.1: only members of
 * `opencatalogi-publishers` (and administrators) change whether a publication
 * is public; an editor (in `opencatalogi-editors`, not in the publishers group)
 * edits everything else, is not offered
 * the publish actions and is refused by the publish endpoints.
 *
 * 🔴 EACH PRINCIPAL IS A REAL ACCOUNT. The editor and the publisher are two
 * Nextcloud accounts this suite provisions over OCS, each in its own request
 * context with an empty cookie jar, so a refusal is the editor's and not a
 * missing login, and an allowed call is the publisher's and not the admin's
 * session riding along.
 *
 * WHAT IT CREATES AND REMOVES. The two accounts (the publisher in
 * `opencatalogi-publishers`, the editor in `opencatalogi-editors`: since the
 * 25 Nov 2026 21:50 CET decision only those two groups may create and edit a
 * publication) and one draft publication each of them creates.
 * The publications are deleted and the accounts removed in afterAll.
 *
 * @e2e openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-tries-to-publish
 * @e2e openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-edits-a-title
 * @e2e openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-an-editor-opens-a-draft
 * @e2e openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md#scenario-a-direct-call-by-an-editor
 * @spec openspec/changes/archive/2026-11-24-operations-publish-rights/specs/publications/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { publicationCatalog } from './publication-catalog.ts'

const APP_API = '/index.php/apps/opencatalogi/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication/publication'
const PUBLISHERS = 'opencatalogi-publishers'
const EDITORS = 'opencatalogi-editors'
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
			const groups = role === 'publisher' ? [PUBLISHERS] : [EDITORS]
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
				data: {
					title: `E2E ${role} draft ${RUN}`,
					summary: 'A draft for the publish-rights check.',
					// Required from review on (publication-required-at-publish), so the publisher's publish is not refused for it.
					description: 'Created by the publish-rights e2e check and removed afterwards.',
				},
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
		// A draft is not published directly: it goes through review first, as a reviewer would move it.
		for (const action of ['submit', 'approve']) {
			const moved = await ctx.post(`/index.php/apps/openregister/api/objects/${publicationId}/transition`, { data: { action, data: {} } })
			expect(moved.ok(), `${action}: ${await moved.text()}`).toBe(true)
		}
		// The draft carries no document; publishing one without documents is confirmed explicitly (409 no-documents otherwise).
		const res = await ctx.post(`${APP_API}/publications/${publicationId}/publish`, { data: { confirmNoDocuments: true } })
		expect(res.status(), await res.text()).toBeLessThan(300)
	})

	test('on the page the editor sees no Publish now', async ({ browser, request, baseURL }) => {
		const catalog = await publicationCatalog(request, `pr-${RUN}`)
		const editor = principals.editor
		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		// A new account sees the support dialog and the walkthrough once; global-setup sets the same flags for the administrator.
		await context.addInitScript(() => {
			window.localStorage.setItem('cn-support-dialog-shown:opencatalogi', '1')
			window.localStorage.setItem('cn-walkthrough-seen:opencatalogi', '999.0.0')
		})
		const page = await context.newPage()
		// A page load does not sign in with basic auth (Nextcloud answers with its login form), so the editor
		// signs in through the form, as global-setup does for the administrator.
		await page.goto('/index.php/login')
		await page.locator('input[name="user"]').fill(editor.uid)
		await page.locator('input[name="password"]').fill(editor.password)
		await page.locator('button[type="submit"]').first().click()
		await page.waitForSelector('#header, header.header', { timeout: 20_000 })
		// The publication detail page (manifest route /publications/:catalogSlug/:id); the four-part route is the generic object page.
		await page.goto(`/index.php/apps/opencatalogi/publications/${catalog.slug}/${editor.publicationId}`)
		// The Visibility card sits below the fold; the detail grid mounts a widget when it scrolls into view.
		const visibility = page.getByTestId('publication-visibility')
		await expect(page.locator('[gs-id]').first()).toBeAttached({ timeout: 45_000 })
		await page.mouse.move(800, 400)
		await expect(async () => {
			await page.mouse.wheel(0, 400)
			await expect(visibility).toBeVisible({ timeout: 500 })
		}).toPass({ timeout: 45_000 })
		await expect(page.getByTestId('publication-publish-now'), 'no Publish now for an editor').toHaveCount(0)
		await context.close()
	})
})
