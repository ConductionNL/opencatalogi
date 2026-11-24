/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * search task 1.7: `GET /api/search` on a running instance answers HTTP 200
 * with results, and the results hold a public publication that matches the
 * search term.
 *
 * WHAT IT CREATES AND REMOVES. One catalogue (through the shared helper) and
 * one public publication with a unique title, deleted in afterAll.
 *
 * @spec openspec/changes/search/tasks.md
 */

import { expect, test } from '@playwright/test'
import { publicationCatalog } from './publication-catalog.ts'

const SEARCH = '/index.php/apps/opencatalogi/api/search'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication/publication'
const WORD = `zoekproef${Date.now().toString(36)}`

test.describe('Search endpoint', () => {
	let publicationId = ''

	test.afterAll(async ({ request }) => {
		if (publicationId !== '') {
			await request.delete(`${OBJECTS}/${publicationId}`).catch(() => undefined)
		}
	})

	test('GET /api/search answers 200 with results', async ({ request }) => {
		await publicationCatalog(request, `search-${WORD}`)
		const created = await request.post(OBJECTS, {
			data: {
				title: `E2E ${WORD}`,
				summary: 'A public publication for the search check.',
				status: 'published',
				publicationDate: new Date(Date.now() - 24 * 3600 * 1000).toISOString(),
			},
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		publicationId = String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '')

		await expect.poll(async () => {
			const res = await request.get(SEARCH, { params: { _search: WORD } })
			if (res.status() !== 200) {
				return `status ${res.status()}`
			}
			const answer = await res.json()
			const results = (answer?.results ?? []) as Array<Record<string, any>>
			return results.some((row) => String(row.id ?? row['@self']?.id ?? '') === publicationId) ? 'found' : `results ${results.length}`
		}, { timeout: 30_000, message: 'the search answers 200 and finds the publication' }).toBe('found')

		const all = await request.get(SEARCH)
		expect(all.status()).toBe(200)
		const envelope = await all.json()
		expect(Array.isArray(envelope.results)).toBe(true)
		expect(envelope.results.length).toBeGreaterThan(0)
	})
})
