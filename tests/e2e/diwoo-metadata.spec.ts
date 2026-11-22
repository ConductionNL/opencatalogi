/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A Woo publication without the metadata the Woo-index requires does not go
 * public, and the editor sees which field is missing (REQ-DWP-003).
 *
 * WHAT THIS SPEC IS LOOKING FOR. A publication in a schema a Woo category
 * declares, saved with a publication date and no `wooCategory`, is refused by
 * DiwooCompletenessListener with `wooCategory` named, through the API and on
 * the form an editor saves. The same publication without a publication date
 * is stored: a draft may be incomplete.
 *
 * WHAT IT NEEDS. A Woo category whose `schemas` names a schema of the
 * publication register. Without one, nothing on the instance is a Woo
 * publication by schema and every test skips with that reason.
 *
 * WHAT IT CREATES AND REMOVES. One catalogue holding the declared pair and one
 * draft publication in it, both deleted in afterAll.
 *
 * @e2e openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#scenario-a-woo-publication-without-a-category-is-not-made-public
 * @e2e openspec/changes/diwoo-metadata-on-the-publication/specs/woo-compliance/spec.md#scenario-a-draft-may-be-incomplete
 * @spec openspec/changes/diwoo-metadata-on-the-publication/tasks.md#4.4
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'
import { dialog, rowAction, waitIndexBody } from './workflows/_crud.ts'

const OC_API = '/index.php/apps/opencatalogi/api'
const OR_API = '/index.php/apps/openregister/api'
const RUN_ID = `dwp-${Date.now()}`

/** A register and schema pair, by id. */
interface Pair {
	register: number
	schema: number
}

/**
 * The publication register's schema that a Woo category declares, or null.
 *
 * @param request An authenticated API request context.
 */
async function declaredPair(request: APIRequestContext): Promise<Pair | null> {
	const categories = await request.get(`${OC_API}/woo/categories`)
	expect(categories.ok(), 'GET /api/woo/categories').toBe(true)
	const rows = ((await categories.json()).results ?? []) as Array<
		Record<string, unknown>
	>
	const declared = new Set<string>()
	for (const row of rows) {
		for (const slug of (row.schemas as unknown[]) ?? []) {
			declared.add(String(slug).toLowerCase())
		}
	}
	if (declared.size === 0) return null

	const registerRes = await request.get(`${OR_API}/registers/publication`)
	expect(registerRes.ok(), 'the publication register resolves').toBe(true)
	const register = await registerRes.json()
	for (const entry of (register?.schemas as unknown[]) ?? []) {
		const id =
			entry && typeof entry === 'object'
				? (entry as Record<string, unknown>).id
				: entry
		const schemaRes = await request.get(`${OR_API}/schemas/${id}`)
		if (!schemaRes.ok()) continue
		const schema = await schemaRes.json()
		if (declared.has(String(schema?.slug ?? '').toLowerCase())) {
			return { register: Number(register.id), schema: Number(schema.id) }
		}
	}
	return null
}

/**
 * The text of a refused save, whatever shape the error body has.
 *
 * @param body The response body.
 */
function refusalText(body: string): string {
	try {
		return JSON.stringify(JSON.parse(body))
	} catch {
		return body
	}
}

test.describe('DiWoo metadata at publish', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	const title = `${RUN_ID} Besluit op Woo-verzoek parkeerbeleid`
	let pair: Pair | null = null
	let catalogId = ''
	let catalogSlug = ''
	let draftId = ''

	test.beforeAll(async ({ request }) => {
		pair = await declaredPair(request)
		if (pair === null) return

		catalogSlug = `${RUN_ID}-cat`
		const catalog = await request.post(
			`${OR_API}/objects/publication/catalog`,
			{
				data: {
					title: `DiWoo check ${RUN_ID}`,
					summary: 'Catalogue for the DiWoo metadata e2e run',
					slug: catalogSlug,
					registers: [pair.register],
					schemas: [pair.schema],
				},
			},
		)
		expect(catalog.ok(), `seed catalogue: ${await catalog.text()}`).toBe(true)
		catalogId = String((await catalog.json()).id)
	})

	test.afterAll(async ({ request }) => {
		if (pair !== null && draftId) {
			await request
				.delete(`${OR_API}/objects/${pair.register}/${pair.schema}/${draftId}`)
				.catch(() => undefined)
		}
		if (catalogId) {
			await request
				.delete(`${OR_API}/objects/publication/catalog/${catalogId}`)
				.catch(() => undefined)
		}
	})

	test('A draft may be incomplete', async ({ request }) => {
		test.skip(pair === null, 'no Woo category declares a schema of the publication register')
		const saved = await request.post(
			`${OR_API}/objects/${pair!.register}/${pair!.schema}`,
			{ data: { title } },
		)
		expect(saved.ok(), `draft save: ${await saved.text()}`).toBe(true)
		draftId = String((await saved.json()).id)
	})

	test('A Woo publication without a category is not made public', async ({
		request,
	}) => {
		test.skip(pair === null || !draftId, 'no declared schema, or the draft was not stored')
		const now = new Date().toISOString()
		const refused = await request.put(
			`${OR_API}/objects/${pair!.register}/${pair!.schema}/${draftId}`,
			{ data: { title, publicationDate: now } },
		)
		expect(refused.ok(), 'a public Woo publication without wooCategory is refused').toBe(false)
		expect(refusalText(await refused.text())).toContain('wooCategory')

		const stored = await request.get(
			`${OR_API}/objects/${pair!.register}/${pair!.schema}/${draftId}`,
		)
		expect(stored.ok()).toBe(true)
		const body = await stored.json()
		expect(body.publicationDate ?? null, 'the publication stays unpublished').toBeNull()
	})

	test('The editor sees wooCategory named when saving with a publication date', async ({
		page,
	}) => {
		test.skip(pair === null || !draftId, 'no declared schema, or the draft was not stored')
		await page.goto(
			`${APP}/publications/${encodeURIComponent(catalogSlug)}?_pair=${pair!.register}-${pair!.schema}`,
		)
		await dismissOverlays(page)
		await waitIndexBody(page)

		await rowAction(page, title, 'edit')
		const form = dialog(page)
		await expect(form).toBeVisible({ timeout: 10_000 })
		// By its label only: a fallback to the first text input would overwrite the title.
		await form
			.getByLabel(/^(publication date|publicatiedatum)\s*\*?$/i)
			.first()
			.fill(new Date().toISOString().slice(0, 10))
		await form
			.getByRole('button', { name: /^(save|opslaan)$/i })
			.first()
			.click()

		// The refusal names the field instead of a generic error, and the form
		// stays open so the editor can fill it.
		await expect(form).toBeVisible()
		await expect(form).toContainText('wooCategory', { timeout: 15_000 })
	})
})
