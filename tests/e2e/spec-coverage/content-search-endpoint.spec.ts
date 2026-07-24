/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * WOO-517 content-search e2e coverage (SCH-PFTS-CONTENT-001/-002/-003).
 *
 * Seeds a publication + a document, attaches a plain-text file carrying a
 * distinctive phrase absent from all metadata fields, force-triggers OR's
 * text-extraction for that file (rather than waiting on the lazy
 * `FileTextExtractionJob` cron — see design.md "Extraction lag" risk), and
 * asserts the phrase surfaces the document via the ANONYMOUS public search
 * endpoint (`GET /apps/opencatalogi/api/search?_search=...&_content=true`) —
 * but NOT when `_content` is omitted, since the phrase is body-text-only.
 *
 * This proves the full chain: OC's `_content` opt-in -> OR's `_content_search`
 * flag -> `ChunkMapper::searchByKeyword()` -> chunk-to-document resolution ->
 * `isObjectPublic()` + transitive publication-visibility (unaffected here,
 * both rows are public) -> flat WOO-506 envelope, dedup on `@self.id`.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test content-search-endpoint
 */
import { test, expect, request, type APIRequestContext } from '@playwright/test'
import { Fixtures, BASE } from '../workflows/_fixtures'

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
			const titles = ((body.results as Array<Record<string, unknown>>) ?? [])
				.map((r) => (r.title as string) ?? '')
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
	test(
		// @e2e search::content-search-surfaces-a-body-text-only-match
		'WOO-517 — a body-text-only match surfaces via ?_content=true and is absent without it',
		async () => {
			const marker = `lorem-ipsum-woo517-marker-${fx.runId}`

			// Publicly visible publication (past publicatiedatum) + a linked document,
			// neither carrying the marker phrase in any metadata field.
			const pastPublicatiedatum = '2020-01-01T00:00:00+00:00'
			const pub = await fx.createPublication('Content Search Publication', {
				publicatiedatum: pastPublicatiedatum,
			})
			const pubSlug = (pub.raw['@self'] as Record<string, unknown>)?.slug as string
				?? (pub.raw.slug as string) ?? ''
			const doc = await fx.createDocument(
				'Content Search Document',
				{ slug: pubSlug, title: pub.title },
				{ publicatiedatum: pastPublicatiedatum, filename: 'content-search-marker.txt' },
			)

			// Attach a plain-text file whose ONLY occurrence of the marker is in the
			// body — never in the document's title/summary/filename metadata.
			const fileId = await fx.attachFile(
				pub.register,
				doc.schema,
				doc.id,
				'content-search-marker.txt',
				`This file exists solely to carry a distinctive phrase: ${marker}. `
					+ 'No metadata field on the owning document repeats this phrase.',
			)
			await fx.extractFile(fileId)

			// Sanity: the anon context really is unauthenticated.
			const whoami = await anon.get('/ocs/v2.php/cloud/user?format=json')
			expect(whoami.status(), 'anon context is unauthenticated').toBe(401)

			// `_content` omitted (WOO-506 baseline) — metadata-only match. The marker
			// lives only in the file body, so the document MUST NOT surface.
			const metadataOnly = await anon.get(
				`/index.php/apps/opencatalogi/api/search?_search=${encodeURIComponent(marker)}`,
			)
			expect(metadataOnly.status(), 'metadata-only search succeeds').toBe(200)
			const metadataOnlyBody = await metadataOnly.json().catch(() => ({}))
			const metadataOnlyTitles = ((metadataOnlyBody.results as Array<Record<string, unknown>>) ?? [])
				.map((r) => (r.title as string) ?? '')
			expect(
				metadataOnlyTitles,
				'a body-text-only match MUST NOT surface without _content=true',
			).not.toContain(doc.title)

			// `_content=true` — widen to body text. Extraction may lag the upload by a
			// short interval even after the force-trigger above (design.md "Extraction
			// lag"), so poll briefly before asserting.
			const withContent = await pollContentSearch(marker, 10, 1000)
			expect(withContent.status, 'content search succeeds').toBe(200)
			expect(
				withContent.titles,
				'the body-text match surfaces the owning document when _content=true',
			).toContain(doc.title)
		},
	)
})
