/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publications task 3 (REQ-PUB-010): the six public publication endpoints of a
 * catalogue answer an anonymous cross-origin browser with CORS headers, on the
 * GET and on the OPTIONS preflight: 12 responses.
 *
 * The headers are asserted whatever the status: a browser drops a 404 without
 * `Access-Control-Allow-Origin` as a network error, so the portal could not
 * tell "no files" from "unreachable".
 *
 * WHAT IT CREATES AND REMOVES. One catalogue (through the shared helper, reused
 * across runs) and one public publication with a unique title, deleted in afterAll.
 *
 * @spec openspec/changes/publications/tasks.md
 * @e2e openspec/changes/publications/specs/publications/spec.md
 */

import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'
import { publicationCatalog } from './publication-catalog.ts'

const API = '/index.php/apps/opencatalogi/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication/publication'
const ORIGIN = 'https://www.voorbeeldgemeente.nl'
const RUN = Date.now().toString(36)

/**
 * The six endpoint variants of REQ-PUB-010 for one catalogue and publication.
 *
 * @param slug The catalogue slug.
 * @param id The publication id.
 */
function endpoints(slug: string, id: string): Record<string, string> {
	const base = `${API}/${encodeURIComponent(slug)}`
	return {
		list: base,
		detail: `${base}/${id}`,
		attachments: `${base}/${id}/attachments`,
		download: `${base}/${id}/download`,
		uses: `${base}/${id}/uses`,
		used: `${base}/${id}/used`,
	}
}

/**
 * The CORS headers a response is missing, by name.
 *
 * @param headers The response headers (lower-case keys, as Playwright gives them).
 * @param preflight Whether this is the OPTIONS answer, which also names its max age.
 */
function missingCors(headers: Record<string, string>, preflight: boolean): string[] {
	const required = [
		'access-control-allow-origin',
		'access-control-allow-methods',
		'access-control-allow-headers',
	]
	if (preflight) {
		required.push('access-control-max-age')
	}
	return required.filter((name) => (headers[name] ?? '').trim() === '')
}

test.describe('CORS on the public publication endpoints', () => {
	let publicationId = ''

	test.afterAll(async ({ request }) => {
		if (publicationId !== '') {
			await request.delete(`${OBJECTS}/${publicationId}`).catch(() => undefined)
		}
	})

	test('all 12 responses (6 GET + 6 OPTIONS) carry the CORS headers', async ({ request, baseURL }) => {
		const catalog = await publicationCatalog(request, 'e2e-cors')
		const created = await request.post(OBJECTS, {
			data: {
				title: `E2E CORS ${RUN}`,
				summary: 'A public publication for the CORS check.',
				publicationDate: new Date(Date.now() - 24 * 3600 * 1000).toISOString(),
			},
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		publicationId = String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '')
		expect(publicationId, 'the publication has an id').not.toBe('')
		// A new publication starts as a draft: take it through review to published.
		for (const status of ['in_review', 'approved', 'published']) {
			const moved = await request.patch(`${OBJECTS}/${publicationId}`, { data: { status } })
			expect(moved.ok(), `to ${status}: ${await moved.text()}`).toBe(true)
		}

		const browser = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
		})
		const gaps: string[] = []
		try {
			for (const [name, url] of Object.entries(endpoints(catalog.slug, publicationId))) {
				const get = await browser.get(url, { headers: { Origin: ORIGIN } })
				for (const header of missingCors(get.headers(), false)) {
					gaps.push(`GET ${name} (${get.status()}): ${header}`)
				}

				const preflight = await browser.fetch(url, {
					method: 'OPTIONS',
					headers: {
						Origin: ORIGIN,
						'Access-Control-Request-Method': 'GET',
					},
				})
				expect(preflight.status(), `OPTIONS ${name} answers`).toBeLessThan(400)
				for (const header of missingCors(preflight.headers(), true)) {
					gaps.push(`OPTIONS ${name}: ${header}`)
				}
			}
		} finally {
			await browser.dispose()
		}

		expect(gaps, 'every response carries the CORS headers').toEqual([])
	})
})
