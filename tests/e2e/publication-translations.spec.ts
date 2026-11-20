/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A publication with an English title and summary and only a Dutch description.
 *
 * WHAT THIS SPEC IS LOOKING FOR. An editor adds an English summary: OpenRegister
 * stores it as a translation and the Dutch summary is unchanged. An anonymous
 * visitor who asks in English gets the English title and summary and the Dutch
 * description, the fallback for the field nobody translated. The publication
 * page's Languages card names the missing English description.
 *
 * @e2e openspec/changes/publication-translations/specs/publications/spec.md#scenario-adding-an-english-summary
 * @e2e openspec/changes/publication-translations/specs/publications/spec.md#scenario-an-english-visitor-reads-a-partly-translated-publication
 * @e2e openspec/changes/publication-translations/specs/publications/spec.md#scenario-a-missing-description-is-named
 */
import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'
import { BASE, Fixtures, newRunId, REG_PUBLICATION, SCHEMA_PUBLICATION } from './workflows/_fixtures.ts'

/** OpenRegister's API base path. */
const OR_API = '/index.php/apps/openregister/api'

/** The app's base path. */
const APP = '/index.php/apps/opencatalogi'

const DUTCH = {
	title: 'Besluit over de herinrichting van het marktplein',
	summary: 'Het college besluit het marktplein opnieuw in te richten.',
	description: 'De herinrichting start in het voorjaar en duurt drie maanden.',
}

const ENGLISH = {
	title: 'Decision on the redesign of the market square',
	summary: 'The executive decides to redesign the market square.',
}

test.describe('Publication translations', () => {
	const fx = new Fixtures()
	const run = newRunId()
	let catalogSlug = ''
	let publicationId = ''

	test.beforeAll(async () => {
		await fx.init()
		const catalog = await fx.createCatalog(`translations-${run}`, { slug: `translations-${run}`.toLowerCase() })
		catalogSlug = String(catalog.raw.slug)
		const publication = await fx.createPublication(
			`translations-${run}`,
			{ ...DUTCH, publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_PUBLICATION,
		)
		publicationId = publication.id
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('an editor adds an English title and summary as a translation', async ({ request }) => {
		const object = `${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${publicationId}`
		const saved = await request.patch(object, {
			headers: { 'X-Translation-Target-Language': 'en' },
			data: ENGLISH,
		})
		expect(saved.ok(), await saved.text()).toBe(true)

		// The Dutch source is untouched by the English save.
		const dutch = await (await request.get(object, { headers: { 'Accept-Language': 'nl' } })).json()
		expect(dutch.title).toBe(DUTCH.title)
		expect(dutch.summary).toBe(DUTCH.summary)
	})

	test('an English visitor reads English title and summary with a Dutch description', async () => {
		const anonymous = await playwrightRequest.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
		})
		const answer = await anonymous.get(`${APP}/api/${catalogSlug}/${publicationId}`, {
			headers: { 'Accept-Language': 'en' },
		})
		expect(answer.ok()).toBe(true)
		const body = await answer.json()
		expect(body.title).toBe(ENGLISH.title)
		expect(body.summary).toBe(ENGLISH.summary)
		expect(body.description).toBe(DUTCH.description)
		expect(answer.headers()['content-language']).toBe('en')
		await anonymous.dispose()
	})

	test('the Languages card names the missing English description', async ({ page }) => {
		await page.goto(`${BASE}${APP}/publications/${catalogSlug}/${publicationId}`)
		const english = page.getByTestId('publication-language-en')
		await expect(english).toBeVisible({ timeout: 30_000 })
		await expect(english).toContainText('Title and summary translated, description missing')
		await expect(page.getByTestId('publication-language-translate-en')).toBeVisible()
	})
})
