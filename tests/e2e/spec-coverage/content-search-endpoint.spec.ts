import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WOO-517 content-search e2e coverage (SCH-PFTS-CONTENT-001/-002/-003).
 *
 * Seeds a publication, attaches a plain-text file carrying a distinctive phrase
 * absent from all metadata fields, force-triggers OR's text-extraction for that
 * file (rather than waiting on the lazy `FileTextExtractionJob` cron — see
 * design.md "Extraction lag" risk), and asserts the phrase surfaces the
 * PUBLICATION via the ANONYMOUS public search endpoint
 * (`GET /apps/opencatalogi/api/search?_search=...&_content=true`) — but NOT when
 * `_content` is omitted, since the phrase is body-text-only.
 *
 * This proves the full chain: OC's `_content` opt-in -> OR's `_content_search`
 * flag -> `ChunkMapper::searchByKeyword()` -> `FileMapper::findOwningObjectUuid()`
 * -> `isObjectPublic()` -> flat WOO-506 envelope, dedup on `@self.id`.
 *
 * The attachment used to be a separate `document` object, which is the only
 * reason the assembler needed to widen its schema scope at all: the chunk's
 * owner sat outside the catalog's scope. A file on the publication resolves to
 * the publication, so this now exercises the ordinary path rather than a
 * special case built for one.
 *
 * Run:
 *   PLAYWRIGHT_BASE_URL=http://localhost:8087 npx playwright test content-search-endpoint
 */
import { expect, request, test } from '@playwright/test'
import { BASE, Fixtures, SCHEMA_PUBLICATION } from '../workflows/_fixtures.ts'

const fx = new Fixtures()

/** Anonymous (no credentials) request context — the public search caller. */
let anon: APIRequestContext

test.beforeAll(async () => {
	await fx.init()
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

/**
 * Poll the public search endpoint until the phrase surfaces or the budget runs out.
 * @param phrase
 * @param attempts
 * @param delayMs
 */
async function pollContentSearch(
	phrase: string,
	attempts: number,
	delayMs: number,
): Promise<{ status: number; titles: string[] }> {
	let last = { status: 0, titles: [] as string[] }
	for (let i = 0; i < attempts; i++) {
		const res = await anon.get(
			`/index.php/apps/opencatalogi/api/search?_search=${encodeURIComponent(phrase)}&_content=true`,
		)
		if (res.ok()) {
			const body = await res.json().catch(() => ({}))
			const titles = (
				(body.results as Array<Record<string, unknown>>) ?? []
			).map((r) => (r.title as string) ?? '')
			last = { status: res.status(), titles }
			if (titles.length > 0) return last
		} else {
			last = { status: res.status(), titles: [] }
		}
		await new Promise((resolve) => setTimeout(resolve, delayMs))
	}
	return last
}

test.describe('content-search-endpoint', () => {
	test(// @e2e search::content-search-surfaces-a-body-text-only-match
	'WOO-517 — a body-text-only match surfaces via ?_content=true and is absent without it', async () => {
		// Text extraction is asynchronous and this test waits on it, so the
		// budget has to cover the wait rather than the work. See the poll below.
		test.setTimeout(120_000)
		const marker = `lorem-ipsum-woo517-marker-${fx.runId}`

		// A publicly visible publication (past publicationDate) carrying the marker
		// phrase in no metadata field at all — only in the body of an attached file.
		//
		// The long note that used to sit here described a failure in the document to
		// publication slug link. There is no such link any more: the attachment is a
		// file on the publication. What survives from it is the one rule that still
		// bites, restated at the point it applies below — OpenRegister does not
		// derive a slug, so one has to be passed explicitly.
		const pastPublicatiedatum = '2020-01-01T00:00:00+00:00'

		// A CATALOG THAT COVERS THE PUBLICATION SCHEMA, created here rather than
		// assumed. `/api/search` derives its scope from listed+published catalogs
		// since WOO-536, so a publication is only reachable when some catalog
		// lists its schema AND the catalog itself is published. A catalog without
		// a past `published` date contributes nothing however its schemas are set.
		//
		// This used to name the DOCUMENT schema too, because the attachment was a
		// separate object that had to be in scope in its own right. It is a file
		// on the publication now, and OpenRegister resolves a file chunk to its
		// OWNING object, so the publication's own scope is the only one involved.
		// That is what retiring `document` bought: the schema widening this test
		// was written for has nothing left to widen.
		await fx.createCatalog('Content Search Catalog', {
			schemas: [SCHEMA_PUBLICATION],
		})

		// The publication MUST be created with an explicit `slug`. OpenRegister
		// does not derive one, and an empty slug has broken this test before.
		const pub = await fx.createPublication('Content Search Publication', {
			publicationDate: pastPublicatiedatum,
			slug: `e2e-${fx.runId}-content-search-pub`,
		})
		const pubSlug =
			((pub.raw['@self'] as Record<string, unknown>)?.slug as string)
			?? (pub.raw.slug as string)
			?? ''
		expect(pubSlug, 'the publication must carry a slug').not.toBe('')

		// Attach a plain-text file DIRECTLY to the publication, whose ONLY
		// occurrence of the marker is in the body — never in any metadata field
		// of the publication or the file.
		const fileId = await fx.attachFile(
			pub.register,
			pub.schema,
			pub.id,
			'content-search-marker.txt',
			`This file exists solely to carry a distinctive phrase: ${marker}. `
				+ 'No metadata field on the owning publication repeats this phrase.',
		)
		await fx.extractFile(fileId)

		// Sanity: the anon context really is unauthenticated.
		const whoami = await anon.get('/ocs/v2.php/cloud/user?format=json')
		expect(whoami.status(), 'anon context is unauthenticated').toBe(401)

		// `_content` omitted (WOO-506 baseline) — metadata-only match. The marker
		// lives only in the file body, so the publication MUST NOT surface.
		const metadataOnly = await anon.get(
			`/index.php/apps/opencatalogi/api/search?_search=${encodeURIComponent(marker)}`,
		)
		expect(metadataOnly.status(), 'metadata-only search succeeds').toBe(200)
		const metadataOnlyBody = await metadataOnly.json().catch(() => ({}))
		const metadataOnlyTitles = (
			(metadataOnlyBody.results as Array<Record<string, unknown>>) ?? []
		).map((r) => (r.title as string) ?? '')
		expect(
			metadataOnlyTitles,
			'a body-text-only match MUST NOT surface without _content=true',
		).not.toContain(pub.title)

		// POSITIVE CONTROL, and the reason this test could fail for a reason it
		// never named. The assertion above is satisfied by an EMPTY list, so it
		// passes identically whether the endpoint correctly withheld one publication
		// or returned nothing at all because the anonymous caller can see no
		// publications whatsoever.
		//
		// That distinction is the whole question when the `_content=true` half
		// below comes back empty: WOO-551 removed OR's `_rbacAsPublic` toggle, and
		// the replacement inherits authorization at the schema level, so anonymous
		// visibility now depends on read rules being present on the register. If
		// they are not, every publication is invisible here, the file chunk can
		// never resolve its owning publication, and the failure surfaces as
		// "the body-text match does not surface" — an assertion about content
		// search, blamed for an authorization gap.
		//
		// The publication carries a past publicationDate, which is exactly what
		// the register's read rules scope anonymous access to, so it MUST be
		// visible. If this fails, stop reading the content-search code.
		const anonPubProbe = await anon.get(
			`/index.php/apps/opencatalogi/api/search?_search=${encodeURIComponent(pub.title)}`,
		)
		expect(anonPubProbe.status(), 'anon publication probe succeeds').toBe(200)
		const anonPubBody = await anonPubProbe.json().catch(() => ({}))
		const anonPubTitles = (
			(anonPubBody.results as Array<Record<string, unknown>>) ?? []
		).map((r) => (r.title as string) ?? '')
		expect(
			anonPubTitles,
			'the anonymous caller must see the seeded publication itself — if this fails, anonymous visibility is broken and the content-search assertion below is measuring the wrong thing',
		).toContain(pub.title)

		// `_content=true` — widen to body text. Extraction may lag the upload by a
		// short interval even after the force-trigger above (design.md "Extraction
		// lag"), so poll briefly before asserting.
		// 30s, not 10. Extraction is queued work: `extractFile()` force-triggers
		// it and returns as soon as the request is accepted, so the marker
		// becomes searchable some time later. Ten seconds was enough on an idle
		// runner and not on a loaded one, which made this test flap rather than
		// fail: it went red on c402608d, green on a07067af and red again on
		// 6012ff52, always alone and always with 111 others passing, and the
		// extraction request itself reported no error in any of them.
		//
		// The loop returns the moment a title appears, so a longer ceiling costs
		// nothing when extraction is prompt. It only stops the suite reporting
		// 'the body-text match does not surface' when the truth is that it had
		// not surfaced YET.
		const withContent = await pollContentSearch(marker, 30, 1000)
		expect(withContent.status, 'content search succeeds').toBe(200)
		expect(
			withContent.titles,
			'the body-text match surfaces the owning publication when _content=true',
		).toContain(pub.title)
	})
})
