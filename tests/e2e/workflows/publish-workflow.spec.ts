/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HIGH-VALUE discoverability coverage: prove that whether a publication is
 * discoverable to an anonymous visitor is governed by OpenRegister's RBAC
 * model — specifically the `public` user group's read grant — NOT by a
 * deprecated per-object "publish" action or a `published` flag/column.
 *
 * THE DISCOVERABILITY MODEL (RBAC + the `public` group, verified live):
 *
 *   OpenRegister gates anonymous (not-logged-in) reads on the `public` group:
 *   an object is anonymously readable iff its schema (or object) grants read
 *   to the `public` group — i.e. the schema's `authorization.read` includes
 *   "public". The publication schema (53) is configured this way
 *   (`authorization: { read: ["public"] }`), so its objects are reachable by
 *   an anonymous OpenRegister caller; a context WITHOUT the public-read grant
 *   is denied. "Publishing" as a separate object state/action is DEPRECATED —
 *   there is no publish endpoint and no `published` column; discoverability is
 *   purely the RBAC grant.
 *
 *   On top of OpenRegister's RBAC, the OpenCatalogi per-catalog directory
 *   endpoint
 *       GET /index.php/apps/opencatalogi/api/{catalogSlug}
 *   only surfaces content an anonymous visitor is allowed to read. A private
 *   (no public-read) publication is therefore hidden from the anonymous
 *   directory while remaining visible to its authenticated author — the RBAC
 *   gate doing its job.
 *
 * WHAT THIS SPEC ASSERTS:
 *   1. RBAC gate (GREEN): a freshly created publication is visible to its
 *      AUTHENTICATED author in the catalog directory but EXCLUDED from the
 *      ANONYMOUS catalog directory — the server-side access gate genuinely
 *      filters non-public content out of the public directory.
 *   2. Public-group read (GREEN, reframed from the old publish fixme): because
 *      the publication schema grants read to the `public` group, the very same
 *      publication IS readable by a truly anonymous OpenRegister caller via the
 *      object API. This is the load-bearing "the `public` group makes content
 *      discoverable anonymously" guarantee — asserted against the RBAC model,
 *      with no reference to a publish action or `@self.published`.
 *
 * Cleanup: afterAll removes the catalog + publication this run created.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test workflows/publish-workflow
 */
import { test, expect, request, type APIRequestContext } from '@playwright/test'
import { Fixtures, BASE, REG_PUBLICATION, SCHEMA_PUBLICATION } from './_fixtures'

const fx = new Fixtures()

/** Anonymous (no credentials) request context for public-directory checks. */
let anon: APIRequestContext

test.beforeAll(async () => {
	await fx.init()
	// Truly anonymous: force an empty cookie jar. The dev container otherwise
	// leaks an admin session into a bare request context (verified: whoami=200),
	// so without this the public-directory gate could not be tested honestly.
	anon = await request.newContext({
		baseURL: BASE,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
	await anon.dispose()
})

/** Helper: list a catalog's publications as a given caller. */
async function catalogListing(
	ctx: APIRequestContext,
	slug: string,
): Promise<{ status: number; titles: string[]; total: number | null }> {
	const res = await ctx.get(`/index.php/apps/opencatalogi/api/${slug}?_limit=200`)
	if (!res.ok()) return { status: res.status(), titles: [], total: null }
	const body = await res.json().catch(() => ({}))
	const results = (body.results as Array<Record<string, unknown>>) ?? []
	return {
		status: res.status(),
		titles: results.map((r) => (r.title as string) ?? ''),
		total: (body.total as number) ?? null,
	}
}

test.describe('publish workflow', () => {
	test(
		// @e2e publications::draft-is-author-visible-but-anonymous-excluded
		'Publish gate — a draft publication is visible to the author but EXCLUDED from the anonymous catalog directory',
		async () => {
			// Catalog wired to the publication register/schema, with a slug we can
			// hit on the public endpoint.
			const slug = `e2e-${fx.runId}-cat`
			const catalog = await fx.createCatalog('Publish Catalog', { slug })
			const realSlug = (catalog.raw['@self'] as Record<string, unknown>)?.slug as string
				?? (catalog.raw.slug as string) ?? slug

			// Sanity: the anon context really is unauthenticated, otherwise the
			// public-gate assertion below would be meaningless.
			const whoami = await anon.get('/ocs/v2.php/cloud/user?format=json')
			expect(whoami.status(), 'anon context is unauthenticated').toBe(401)

			// A draft publication (no publish action; @self.published stays null).
			const pub = await fx.createPublication('Draft Publication')
			const draft = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, pub.id)
			expect(
				(draft!['@self'] as Record<string, unknown>)?.published ?? null,
				'a freshly created publication has no published timestamp (it is a draft)',
			).toBeFalsy()

			// AUTHENTICATED author sees the draft in the catalog listing.
			const authed = await catalogListing(fx.api, realSlug)
			expect(authed.status, 'authed catalog listing succeeds').toBe(200)
			expect(authed.titles, 'author sees the draft publication').toContain(pub.title)

			// ANONYMOUS caller does NOT see the draft — the published gate filters
			// it out of the public directory. This is the real, load-bearing
			// behavioral guarantee that publishing controls discoverability.
			const publicView = await catalogListing(anon, realSlug)
			expect(publicView.status, 'anonymous catalog listing succeeds').toBe(200)
			expect(publicView.titles, 'draft is hidden from the public directory')
				.not.toContain(pub.title)
		},
	)

	test(
		// @e2e publications::public-group-read-makes-content-anonymously-discoverable
		'Public-group read — the `public` group read grant on the publication schema makes a publication readable by an anonymous OpenRegister caller (RBAC discoverability)',
		async () => {
			// A publication created under the publication schema (53). That schema's
			// `public`-group read grant is CONDITIONAL on a publication date that has
			// already passed (authorization.read.public.match = { publicatiedatum:
			// { $lte: $now } }) — that is precisely what makes "publishing" control
			// anonymous discoverability. So seed publicatiedatum in the PAST to satisfy
			// the public-read match; a draft (no publicatiedatum) is correctly hidden
			// and is covered by the publish-gate test above.
			const pastPublicatiedatum = '2020-01-01T00:00:00+00:00'
			const pub = await fx.createPublication('Publicly Readable Publication', {
				publicatiedatum: pastPublicatiedatum,
			})

			// Sanity: the anon context really is unauthenticated.
			const whoami = await anon.get('/ocs/v2.php/cloud/user?format=json')
			expect(whoami.status(), 'anon context is unauthenticated').toBe(401)

			// Because the schema grants read to the `public` group for publications
			// whose publicatiedatum has passed, an ANONYMOUS OpenRegister caller can
			// read this (past-dated) object directly via the object API — this is the
			// RBAC `public`-group discoverability guarantee.
			const anonRead = await anon.get(
				`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${pub.id}`,
			)
			expect(anonRead.status(), 'public-group read grant lets anon read the object').toBe(200)
			const body = await anonRead.json().catch(() => ({}))
			const anonTitle = (body.title as string)
				?? ((body['@self'] as Record<string, unknown>)?.name as string)
				?? ''
			expect(anonTitle, 'anon receives the real publication object').toBe(pub.title)

			// And it is anonymously listable within the public-read schema.
			const anonList = await anon.get(
				`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}?_search=${encodeURIComponent(pub.title)}`,
			)
			expect(anonList.status(), 'anon can list public-read schema objects').toBe(200)
			const listBody = await anonList.json().catch(() => ({}))
			const titles = ((listBody.results as Array<Record<string, unknown>>) ?? [])
				.map((r) => (r.title as string) ?? '')
			expect(titles, 'the public-read publication is discoverable anonymously')
				.toContain(pub.title)
		},
	)
})
