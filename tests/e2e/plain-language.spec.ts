/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — a plain language summary on a publication
 * (change publication-plain-language-and-translation, REQ-PPL-001, REQ-PPL-002).
 *
 * An officer stores a B1 summary for a fresh publication; the publication page
 * shows it with its level and the public API answers both. A save with a
 * summary and no level is refused naming `plainSummaryLevel`.
 *
 * Drafting depends on whether the instance has a text assistant for
 * `core:text2text:simplification`. The e2e seed registers none, so on CI the
 * draft test proves the no-provider half of REQ-PPL-002: the page offers no
 * Draft and says why, and the draft route answers 409 `no-provider`. On an
 * instance that has a provider the same test walks the suggestion-then-accept
 * flow instead. Either way it asserts; nothing is skipped.
 *
 * @spec openspec/changes/publication-plain-language-and-translation/specs/publications/spec.md
 * @e2e publication-plain-language-and-translation/requirement-a-publication-carries-a-plain-language-summary-with-its-level-req-ppl-001/an-officer-writes-a-b1-summary-and-the-reader-sees-it
 * @e2e publication-plain-language-and-translation/requirement-a-drafted-plain-summary-is-a-suggestion-an-officer-accepts-req-ppl-002/a-draft-is-a-suggestion-until-accepted
 */
import { expect, test } from './authenticated-request.ts'
import { BASE_URL } from './base-url.ts'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

const OR_API = '/index.php/apps/openregister/api'
const API = '/index.php/apps/opencatalogi/api'
const RUN_ID = `ppl-${Date.now()}`
const SUMMARY = 'U kreeg geen parkeervergunning. U maakte bezwaar. De gemeente blijft bij het besluit.'

test.describe.configure({ mode: 'serial' })

test.describe('A plain language summary on a publication', () => {
	let scope = { register: 0, schema: 0 }
	let slug = ''
	const created: string[] = []

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		slug = (await publicationCatalog(request, RUN_ID)).slug
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request.delete(`${OR_API}/objects/${scope.register}/${scope.schema}/${id}`)
		}
	})

	/**
	 * Create a public publication for this run.
	 *
	 * @param request A signed-in request context.
	 * @param title   Its title, after the run id.
	 * @return Its id.
	 */
	async function publication(request: any, title: string): Promise<string> {
		const res = await request.post(`${OR_API}/objects/${scope.register}/${scope.schema}`, {
			data: {
				description: 'Created by the plain language e2e spec.',
				publicationDate: new Date(Date.now() - 86_400_000).toISOString(),
				summary: 'Fixture publication',
				title: `${RUN_ID} ${title}`,
			},
		})
		expect(res.ok(), `seed ${title}: HTTP ${res.status()}`).toBe(true)
		const id = String((await res.json()).id)
		created.push(id)
		return id
	}

	test('an officer writes a B1 summary and the reader sees it', async ({ page, playwright, request }) => {
		const id = await publication(request, 'Besluit op bezwaar parkeervergunning')

		const saved = await request.post(`${API}/publications/${id}/plain-summary`, {
			data: { level: 'B1', text: SUMMARY },
		})
		expect(saved.ok(), `accept: HTTP ${saved.status()} ${await saved.text()}`).toBe(true)

		const stored = await (await request.get(`${OR_API}/objects/${scope.register}/${scope.schema}/${id}`)).json()
		expect(stored.plainSummary).toBe(SUMMARY)
		expect(stored.plainSummaryLevel).toBe('B1')
		expect(stored.plainSummaryProvenance?.generatedBy).toBe('person')
		expect(stored.plainSummaryProvenance?.acceptedBy).toBeTruthy()

		await page.goto(`${APP}/publications/${encodeURIComponent(slug)}/${id}`)
		await dismissOverlays(page)
		const main = page.locator('main, .app-content').first()
		await expect(main.getByText(SUMMARY).first()).toBeVisible({ timeout: 30_000 })
		await expect(main.getByText('B1', { exact: true }).first()).toBeVisible()

		const anonymous = await playwright.request.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		})
		const pub = await anonymous.get(`${API}/${encodeURIComponent(slug)}/${id}`)
		expect(pub.status(), await pub.text()).toBe(200)
		const body = await pub.json()
		expect(body.plainSummary).toBe(SUMMARY)
		expect(body.plainSummaryLevel).toBe('B1')
		await anonymous.dispose()
	})

	test('a summary without its level is refused naming the level', async ({ request }) => {
		const id = await publication(request, 'Zonder niveau')
		const url = `${OR_API}/objects/${scope.register}/${scope.schema}/${id}`
		const current = await (await request.get(url)).json()
		const { '@self': _self, ...data } = current
		const refused = await request.put(url, {
			data: { ...data, plainSummary: SUMMARY, plainSummaryLevel: '' },
		})
		expect(refused.status()).toBeGreaterThanOrEqual(400)
		expect(await refused.text()).toContain('plainSummaryLevel')

		const after = await (await request.get(url)).json()
		expect(after.plainSummary ?? '').toBe('')
	})

	test('a draft is a suggestion until accepted', async ({ page, request }) => {
		const id = await publication(request, 'Concept')
		const url = `${OR_API}/objects/${scope.register}/${scope.schema}/${id}`
		const assistant = await (await request.get(`${API}/plain-summary/assistant`)).json()

		await page.goto(`${APP}/publications/${encodeURIComponent(slug)}/${id}`)
		await dismissOverlays(page)
		const widget = page.getByTestId('plain-summary-draft')
		await expect(widget).toBeVisible({ timeout: 30_000 })

		if (assistant.available !== true) {
			// No text assistant: no Draft, the page says why, and the route refuses.
			await expect(page.getByTestId('plain-summary-no-assistant')).toBeVisible()
			await expect(page.getByTestId('plain-summary-draft-start')).toHaveCount(0)
			const refused = await request.post(`${API}/publications/${id}/plain-summary-draft`)
			expect(refused.status()).toBe(409)
			expect((await refused.json()).error).toBe('no-provider')
			return
		}

		await page.getByTestId('plain-summary-draft-start').click()
		const text = page.getByTestId('plain-summary-draft-text').locator('textarea')
		await expect(text).toBeVisible({ timeout: 120_000 })
		expect((await (await request.get(url)).json()).plainSummary ?? '').toBe('')

		const edited = `${(await text.inputValue()).trim()} (nagekeken)`
		await text.fill(edited)
		await page.getByTestId('plain-summary-draft-accept').click()
		await expect(page.getByTestId('plain-summary-draft-message')).toBeVisible({ timeout: 30_000 })

		const stored = await (await request.get(url)).json()
		expect(stored.plainSummary).toBe(edited)
		expect(stored.plainSummaryProvenance?.generatedBy).toBe('ai')
		expect(stored.plainSummaryProvenance?.provider).toBeTruthy()
		expect(stored.plainSummaryProvenance?.acceptedBy).toBeTruthy()
	})
})
