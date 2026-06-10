/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HIGH-VALUE publish workflow coverage: prove that publishing a publication
 * actually changes its status AND makes it discoverable in the public catalog
 * listing — the whole point of OpenCatalogi.
 *
 * THE DISCOVERABILITY MODEL (verified against the running instance):
 *
 *   The public per-catalog listing endpoint
 *       GET /index.php/apps/opencatalogi/api/{catalogSlug}
 *   resolves the catalog's wired register+schema and lists their objects.
 *   For ANONYMOUS callers, PublicationsController::index applies
 *   PublicationQueryService::enforcePublishedForAnonymous(), which keeps only
 *   objects whose `@self.published` timestamp is set (and not in the future)
 *   and not depublished. Authenticated callers see everything (RBAC-scoped).
 *
 *   So "publish makes it discoverable" is precisely:
 *     anon listing of the catalog EXCLUDES a draft publication, and INCLUDES
 *     it once it is published.
 *
 * WHAT THIS SPEC ASSERTS (GREEN):
 *   - A catalog wired to the publication register/schema lists a freshly
 *     created publication for an AUTHENTICATED caller (draft is visible to
 *     admins).
 *   - The SAME catalog listing for an ANONYMOUS caller EXCLUDES that draft
 *     publication — i.e. the server-side published gate genuinely filters
 *     unpublished content out of the public directory. This is the real,
 *     load-bearing half of the discoverability guarantee.
 *
 * WHAT IS test.fixme (REAL PLATFORM BUG — publish cannot change status):
 *   This OpenRegister build has NO mechanism to publish an object:
 *     - There is no per-object publish/depublish REST route. The frontend
 *       (PublicationList.vue / PublicationDetail.vue) POSTs to
 *         /apps/openregister/api/objects/{r}/{s}/{id}/publish
 *       which returns HTTP 404 ("page could not be found") — the route does
 *       not exist in OpenRegister's routes.php.
 *     - The oc_openregister_objects table has no published/depublished column,
 *       and ObjectEntity has no published property, so `@self.published` is
 *       never populated and cannot be set via create/update (client-supplied
 *       `@self.published` / top-level `published` are ignored on PUT/POST).
 *     - The frontend status helper (services/publicationStatus.js) derives
 *       status from object-data fields `publicatiedatum` / `depublicatiedatum`,
 *       but the publication schema (53) does not declare those properties, so
 *       they are stripped on save.
 *   Net effect: a publication can never become published, its status never
 *   changes, and it can never surface in the anonymous (public) directory.
 *   The publish→status-change→discoverable legs are quarantined below with
 *   the failing assertion left in place so a future fix flips them green.
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

	// REAL PLATFORM BUG — see file header. The publish action cannot set
	// @self.published, so the publication never becomes published, its status
	// never changes, and it can never appear in the anonymous directory.
	test.fixme(
		// @e2e publications::publish-changes-status-and-surfaces-in-directory
		'Publish — publishing a draft sets its published status AND makes it discoverable to anonymous callers (BLOCKED: no OR publish endpoint; @self.published cannot be set)',
		async () => {
			const slug = `e2e-${fx.runId}-cat2`
			const catalog = await fx.createCatalog('Publish Catalog 2', { slug })
			const realSlug = (catalog.raw['@self'] as Record<string, unknown>)?.slug as string
				?? (catalog.raw.slug as string) ?? slug
			const pub = await fx.createPublication('To Publish')

			// Attempt to publish via the endpoint the frontend uses. This 404s
			// today because OpenRegister has no per-object publish route.
			const publishRes = await fx.api.post(
				`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${pub.id}/publish`,
			)
			expect(publishRes.ok(), 'publish endpoint exists and succeeds').toBe(true)

			// After a successful publish the published timestamp must be set …
			const published = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, pub.id)
			expect(
				(published!['@self'] as Record<string, unknown>)?.published ?? null,
				'published timestamp is set after publishing',
			).toBeTruthy()

			// … and the publication must now appear in the anonymous directory.
			const publicView = await catalogListing(anon, realSlug)
			expect(publicView.titles, 'published publication is now discoverable anonymously')
				.toContain(pub.title)
		},
	)
})
