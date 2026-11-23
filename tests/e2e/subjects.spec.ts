/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * subjects-as-first-class-records: a featured subject is served for the portal
 * in its order (REQ-SUB-001, REQ-SUB-002), and a subject lists the public
 * publications filed under it, newest first (REQ-SUB-003).
 *
 * WHAT THIS SPEC IS LOOKING FOR, through the object API the theme form saves
 * through and the public routes the portal reads:
 * - Two subjects featured with order 2 and order 1 come back from
 *   `GET /api/themes?featured=true` in that order, order 1 first.
 * - `GET /api/themes/{slug}/publications`, read anonymously, lists the three
 *   public publications filed under the subject, newest publication date
 *   first, and not the draft.
 *
 * NOT HERE YET. The Featured toggle on the theme form and the publication
 * list on the officer's theme page (task 4.1) are not built; their clicks join
 * this spec when they are.
 *
 * WHAT IT CREATES AND REMOVES. Two subjects and four publications, deleted in
 * afterAll.
 *
 * @e2e openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#scenario-an-administrator-features-a-subject REQ-SUB-001
 * @e2e openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md#scenario-a-subject-lists-what-is-filed-under-it REQ-SUB-003
 * @spec openspec/changes/subjects-as-first-class-records/specs/subjects/spec.md
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP_API = '/index.php/apps/opencatalogi/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication'
const RUN = Date.now().toString(36)

/**
 * The id of a created object, whichever key the answer carries it under.
 *
 * @param body The answer of the create call.
 * @return The id.
 */
function idOf(body: Record<string, unknown>): string {
	const self = (body?.['@self'] ?? {}) as Record<string, unknown>
	return String(body?.id ?? self.id ?? body?.uuid ?? '')
}

test.describe('Subjects', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	const created: Array<{ schema: string, id: string }> = []

	/**
	 * Create an object as the administrator and remember it for cleanup.
	 *
	 * @param request The administrator's request context.
	 * @param schema The schema slug.
	 * @param data The object.
	 * @return The id.
	 */
	async function create(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
		const res = await request.post(`${OBJECTS}/${schema}`, { data })
		expect(res.ok(), `creating a ${schema}: ${await res.text()}`).toBe(true)
		const id = idOf(await res.json())
		created.push({ schema, id })
		return id
	}

	test.afterAll(async ({ request }) => {
		for (const { schema, id } of created.reverse()) {
			await request.delete(`${OBJECTS}/${schema}/${id}`).catch(() => undefined)
		}
	})

	test('an administrator features a subject', async ({ request, playwright, baseURL }) => {
		const second = await create(request, 'theme', { title: `E2E Zwembad ${RUN}`, slug: `e2e-zwembad-${RUN}`, description: 'Tweede.' })
		const first = await create(request, 'theme', { title: `E2E Parkeren ${RUN}`, slug: `e2e-parkeren-${RUN}`, description: 'Eerste.' })

		for (const [id, order] of [[second, 2], [first, 1]] as const) {
			const res = await request.patch(`${OBJECTS}/theme/${id}`, { data: { featured: true, featuredOrder: order } })
			expect(res.ok(), `featuring ${id}: ${await res.text()}`).toBe(true)
		}

		const anonymous = await playwright.request.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		try {
			const res = await anonymous.get(`${APP_API}/themes?featured=true`)
			expect(res.ok(), await res.text()).toBe(true)
			const ids = (((await res.json())?.results ?? []) as Array<{ id?: string }>).map((subject) => String(subject.id))
			expect(ids).toContain(first)
			expect(ids).toContain(second)
			expect(ids.indexOf(first), 'order 1 comes before order 2').toBeLessThan(ids.indexOf(second))
		} finally {
			await anonymous.dispose()
		}
	})

	test('a subject lists what is filed under it', async ({ request, playwright, baseURL }) => {
		const slug = `e2e-sporthal-${RUN}`
		const subject = await create(request, 'theme', { title: `E2E Sporthal ${RUN}`, slug })

		const dates = ['2026-03-01T09:00:00+00:00', '2026-05-01T09:00:00+00:00', '2026-04-01T09:00:00+00:00']
		const byDate: Record<string, string> = {}
		for (const [index, publicationDate] of dates.entries()) {
			byDate[publicationDate] = await create(request, 'publication', {
				title: `E2E Sporthal besluit ${index + 1} ${RUN}`,
				summary: 'Filed under the sporthal subject.',
				themes: [subject],
				publicationDate,
			})
		}
		const draft = await create(request, 'publication', {
			title: `E2E Sporthal concept ${RUN}`,
			summary: 'A draft under the same subject.',
			themes: [subject],
		})

		const anonymous = await playwright.request.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		try {
			const res = await anonymous.get(`${APP_API}/themes/${slug}/publications`)
			expect(res.ok(), await res.text()).toBe(true)
			const ids = (((await res.json())?.results ?? []) as Array<Record<string, unknown>>).map((row) => idOf(row))
			const newestFirst = [...dates].sort().reverse().map((date) => byDate[date])
			expect(ids).toEqual(newestFirst)
			expect(ids).not.toContain(draft)
		} finally {
			await anonymous.dispose()
		}
	})
})
