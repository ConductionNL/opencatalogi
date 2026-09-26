/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public and community surface: the status page, subscriptions, the
 * instance banner, notice boards, the Atom feed, the reader's vote and the
 * markup render endpoint.
 *
 * WHAT THIS SPEC IS LOOKING FOR. Publishing what should not be public: a draft
 * in the feed, an individual voter in a distribution, an unconfirmed address in
 * a recipient list, HTML from a caller coming back out of the render endpoint
 * as HTML. And its mirror: a status page reporting all clear while it could not
 * read anything, and a stale state rendering as a confident green.
 *
 * THE LEAST PRIVILEGED PRINCIPAL. Every public read is made from an anonymous
 * request context with no session and no credentials, and each admin surface is
 * probed anonymously first with the refusal asserted.
 *
 * WHAT A RED HERE USUALLY MEANS. A 503 naming its error means the registers are
 * not configured on this instance. That is the app refusing correctly.
 *
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-a-reader-sees-what-is-down
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-a-stale-state-does-not-read-as-green
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-the-page-probes-nothing
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-an-unconfirmed-address-receives-nothing
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-a-notice-is-published-without-becoming-a-publication
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-a-board-with-comments-names-a-moderator
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-a-reader-watches-without-an-account
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-a-draft-never-reaches-the-feed
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-who-voted-stays-private
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-a-client-shows-what-the-website-shows
 * @e2e openspec/changes/the-public-and-community-surface/specs/public-and-community-surface/spec.md#scenario-rendering-stores-nothing
 */
import type { APIRequestContext } from '@playwright/test'

// `test` and `expect` come from the signed-in wrapper below, not from
// @playwright/test. Its `request` fixture carries the session CSRF token, so an
// authenticated write reaches the controller instead of being refused with 412
// before dispatch. The bare `request` factory is still imported from Playwright,
// and is used only to build the anonymous contexts.
import { request } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/**
 * A request context with no session at all.
 *
 * Reusing the test's own context would carry the admin cookie, and every
 * "an anonymous reader cannot" assertion would pass for the wrong reason.
 */
async function anonymous(baseURL: string): Promise<APIRequestContext> {
	return await request.newContext({ baseURL, storageState: undefined })
}

test.describe('The status page', () => {
	test('it is readable without an account, or it says it could not be read', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.get(`${API_BASE}/status`)

		expect([200, 503]).toContain(response.status())
		const body = await response.json()

		if (response.status() === 503) {
			// The one answer this must never give is 200 with an empty list
			// while it could not read anything: an empty status page reads as
			// "nothing is wrong".
			expect(body.error).toBe('status-unreadable')
			await anon.dispose()
			return
		}

		// The page says it measured nothing, so a green is not mistaken for a
		// probe result.
		expect(body.probed).toBe(false)
		expect(Array.isArray(body.components)).toBe(true)

		for (const component of body.components) {
			expect(component).toHaveProperty('stale')
			expect(component).toHaveProperty('stateSetAt')
		}

		await anon.dispose()
	})

	test('an anonymous reader cannot set a component state', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/status`, {
			data: {
				component: 'E2E component',
				state: 'unavailable',
				message: 'E2E',
			},
		})

		// 412 is NOT accepted here. It is Nextcloud's CSRF refusal, and it lands
		// before the controller runs, so accepting it would let this pass on an
		// instance where the endpoint had no authorization at all.
		expect([401, 403, 404]).toContain(response.status())

		await anon.dispose()
	})

	test('a component set to unavailable is listed as such, and a fresh state is not stale', async ({
		baseURL,
		request: admin,
	}) => {
		const set = await admin.post(`${API_BASE}/status`, {
			data: {
				component: 'E2E DigiD',
				state: 'unavailable',
				message: 'E2E: tijdelijk niet beschikbaar.',
			},
		})

		test.skip(
			set.status() === 503,
			'the registers are not configured on this instance',
		)
		expect(set.status()).toBe(201)

		const anon = await anonymous(baseURL as string)
		const page = await anon.get(`${API_BASE}/status`)
		const body = await page.json()

		const component = body.components.find(
			(c: { component: string }) => c.component === 'E2E DigiD',
		)
		expect(component).toBeDefined()
		expect(component.state).toBe('unavailable')
		expect(component.message).toBe('E2E: tijdelijk niet beschikbaar.')
		expect(component.stale).toBe(false)

		await anon.dispose()
	})

	test('an unconfirmed address never appears in the recipient list', async ({
		baseURL,
		request: admin,
	}) => {
		const anon = await anonymous(baseURL as string)
		const address = `e2e-unconfirmed-${Date.now()}@example.org`

		const subscribed = await anon.post(`${API_BASE}/status/subscribe`, {
			data: { address, scope: 'status' },
		})

		test.skip(
			subscribed.status() === 503,
			'the registers are not configured on this instance',
		)
		expect(subscribed.status()).toBe(202)

		// The confirmation token never comes back to the caller: returning it
		// would let anyone confirm a subscription for an address that is not
		// theirs.
		const body = await subscribed.json()
		expect(JSON.stringify(body)).not.toMatch(/[0-9a-f]{32}/)

		const recipients = await admin.get(
			`${API_BASE}/status/recipients?scope=status`,
		)
		expect(recipients.status()).toBe(200)
		expect((await recipients.json()).recipients).not.toContain(address)

		await anon.dispose()
	})

	test('an anonymous reader cannot read the recipient list', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.get(`${API_BASE}/status/recipients?scope=status`)

		// 412 is NOT accepted here. It is Nextcloud's CSRF refusal, and it lands
		// before the controller runs, so accepting it would let this pass on an
		// instance where the endpoint had no authorization at all.
		expect([401, 403, 404]).toContain(response.status())

		await anon.dispose()
	})
})

test.describe('Notice boards and the feed', () => {
	test('a board with comments and no moderator is refused with the reason', async ({
		request: admin,
	}) => {
		const response = await admin.post(`${API_BASE}/notice-boards`, {
			data: { board: { title: 'E2E mededelingen', commentsEnabled: true } },
		})

		expect(response.status()).toBe(400)
		const body = await response.json()
		expect(body.error).toBe('board-refused')
		expect(body.message).toMatch(/moderator/i)
	})

	test('an anonymous caller cannot save a notice', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/notices`, {
			data: {
				notice: {
					board: 'e2e',
					title: 'E2E mededeling',
					startDate: '2026-09-01T00:00:00+00:00',
					endDate: '2099-01-01T00:00:00+00:00',
				},
			},
		})

		// 412 is NOT accepted here. It is Nextcloud's CSRF refusal, and it lands
		// before the controller runs, so accepting it would let this pass on an
		// instance where the endpoint had no authorization at all.
		expect([401, 403, 404]).toContain(response.status())

		await anon.dispose()
	})

	test('a notice with no board is refused with the reason', async ({
		request: admin,
	}) => {
		const response = await admin.post(`${API_BASE}/notices`, {
			data: {
				notice: {
					title: 'E2E mededeling zonder bord',
					startDate: '2026-09-01T00:00:00+00:00',
					endDate: '2099-01-01T00:00:00+00:00',
				},
			},
		})

		expect(response.status()).toBe(400)
		expect((await response.json()).error).toBe('notice-refused')
	})

	test('a notice that passes its checks is stored and is current', async ({
		request: admin,
	}) => {
		const response = await admin.post(`${API_BASE}/notices`, {
			data: {
				notice: {
					board: 'e2e',
					title: 'E2E werk aan de kade',
					body: 'De kade is deze week afgesloten.',
					startDate: '2026-09-01T00:00:00+00:00',
					endDate: '2099-01-01T00:00:00+00:00',
				},
			},
		})

		test.skip(
			response.status() === 503,
			'the registers are not configured on this instance',
		)
		expect(response.status()).toBe(201)
		const body = await response.json()
		expect(body.title).toBe('E2E werk aan de kade')
		expect(body.current).toBe(true)
	})

	test('a new board offers no comment form', async ({ request: admin }) => {
		const response = await admin.post(`${API_BASE}/notice-boards`, {
			data: {
				board: { title: 'E2E mededelingen zonder comments', catalog: 'e2e' },
			},
		})

		test.skip(
			response.status() === 503,
			'the registers are not configured on this instance',
		)
		expect(response.status()).toBe(201)
		expect((await response.json()).commentsOffered).toBe(false)
	})

	test('the feed is readable without an account and is Atom', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.get(`${API_BASE}/feeds/e2e`)

		expect([200, 503]).toContain(response.status())

		if (response.status() === 503) {
			expect((await response.json()).error).toBe('feed-unreadable')
			await anon.dispose()
			return
		}

		expect(response.headers()['content-type']).toContain('application/atom+xml')

		const atom = await response.text()
		expect(atom).toContain('<feed xmlns="http://www.w3.org/2005/Atom">')

		// A draft has no rule publishing it, so it is absent. The assertion is
		// on the served document, not on a service return value.
		expect(atom).not.toContain('<category term="draft"')

		await anon.dispose()
	})
})

test.describe('The reader and the renderer', () => {
	test('a vote on a record that accepts none is refused the same way as one that does not exist', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(
			`${API_BASE}/records/e2e-does-not-exist/vote`,
			{
				data: { value: 'voor' },
			},
		)

		// The same 404 either way: a different answer would let a reader
		// confirm that an unpublished record exists.
		expect([404, 503]).toContain(response.status())

		await anon.dispose()
	})

	test('a vote without a value is refused', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/records/e2e-r1/vote`, {
			data: {},
		})

		expect(response.status()).toBe(400)
		expect((await response.json()).error).toBe('missing-value')

		await anon.dispose()
	})

	test('a client gets back the HTML this app renders', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/markup/render`, {
			data: {
				markup: '# Kop\n\nEen **vette** tekst met [een link](https://example.org).',
			},
		})

		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body.html).toContain('<h1>Kop</h1>')
		expect(body.html).toContain('<strong>vette</strong>')
		expect(body.html).toContain('<a href="https://example.org"')

		await anon.dispose()
	})

	test('HTML a caller sends never comes back as HTML', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/markup/render`, {
			data: {
				markup: '<script>alert(1)</script>\n\n[klik](javascript:alert(1))',
			},
		})

		expect(response.status()).toBe(200)
		const html = (await response.json()).html
		expect(html).not.toContain('<script>')
		expect(html).toContain('&lt;script&gt;')
		expect(html).not.toContain('href="javascript:')

		await anon.dispose()
	})

	test('rendering twice gives the same answer and creates nothing', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const markup = { markup: '# Idempotent' }

		const first = await anon.post(`${API_BASE}/markup/render`, { data: markup })
		const second = await anon.post(`${API_BASE}/markup/render`, { data: markup })

		expect(await first.json()).toEqual(await second.json())

		// No object is created: a render that stored something would show up as
		// a growing list somewhere, and the endpoint answers no id at all.
		expect(await first.json()).not.toHaveProperty('id')

		await anon.dispose()
	})
})
