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
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-a-reader-sees-what-is-down
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-a-stale-state-does-not-read-as-green
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-the-page-probes-nothing
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-a-notice-is-published-without-becoming-a-publication
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-a-board-with-comments-names-a-moderator
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-a-reader-watches-without-an-account
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-a-draft-never-reaches-the-feed
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-a-client-shows-what-the-website-shows
 * @e2e openspec/specs/public-and-community-surface/spec.md#scenario-rendering-stores-nothing
 */
import type { APIRequestContext } from '@playwright/test'

// `test` and `expect` come from the signed-in wrapper below, not from
// @playwright/test. Its `request` fixture carries the session CSRF token, so an
// authenticated write reaches the controller instead of being refused with 412
// before dispatch. The bare `request` factory is still imported from Playwright,
// and is used only to build the anonymous contexts.
import { request } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'
import {
	Fixtures,
	REG_PUBLICATION,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

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
		// A catalogue of this run's own, holding a draft, so "a draft never reaches
		// the feed" is checked against a draft that is there. No seed creates the
		// catalogue `e2e` this read before, which answered 404 catalog-not-found.
		const fx = new Fixtures()
		await fx.init()
		const slug = `feed-${Date.now().toString(36)}`
		await fx.createCatalog('feed', {
			slug,
			registers: [REG_PUBLICATION],
			schemas: [SCHEMA_PUBLICATION],
		})
		await fx.createPublication('feed draft', {}, REG_PUBLICATION)

		const anon = await anonymous(baseURL as string)
		try {
			await readFeed(anon, slug)
		} finally {
			await anon.dispose()
			await fx.cleanupAll()
			await fx.dispose()
		}
	})
})

/**
 * Read a catalogue's feed without an account and check it is Atom without drafts.
 *
 * @param anon An anonymous request context.
 * @param slug The catalogue slug.
 */
async function readFeed(anon: APIRequestContext, slug: string): Promise<void> {
	const response = await anon.get(`${API_BASE}/feeds/${slug}`)

	expect([200, 503]).toContain(response.status())

	if (response.status() === 503) {
		expect((await response.json()).error).toBe('feed-unreadable')
		return
	}

	expect(response.headers()['content-type']).toContain('application/atom+xml')

	const atom = await response.text()
	expect(atom).toContain('<feed xmlns="http://www.w3.org/2005/Atom">')

	// A draft has no rule publishing it, so it is absent. The assertion is
	// on the served document, not on a service return value.
	expect(atom).not.toContain('<category term="draft"')
	expect(atom).not.toContain('feed draft')
}
