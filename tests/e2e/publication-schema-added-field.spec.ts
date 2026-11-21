/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A property an administrator adds to the publication schema in OpenRegister
 * shows on the publication form and is served publicly (REQ-CVS-001).
 *
 * WHAT THIS SPEC IS LOOKING FOR. After adding the text property `zaaknummer`
 * to the publication schema through OpenRegister's schema API, Add on the
 * Publications page offers it, a publication saved with it carries it, and the
 * public publication endpoint returns it once the publication is public. Marked
 * required, a save without it is refused and the form names it.
 *
 * 🔴 IT CHANGES A SHARED SCHEMA. The schema's properties and required list are
 * read first and written back in afterAll, also when a test fails.
 *
 * WHAT IT CREATES AND REMOVES. The `zaaknummer` property (removed again), one
 * catalogue (through publicationCatalog) and one publication (deleted).
 *
 * @e2e openspec/changes/capability-rows-verify-scenarios/specs/publications/spec.md#scenario-an-administrator-adds-a-case-number
 * @e2e openspec/changes/capability-rows-verify-scenarios/specs/publications/spec.md#scenario-an-added-field-that-is-required
 * @spec openspec/changes/capability-rows-verify-scenarios/specs/publications/spec.md
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'
import { dialog, fillField, waitIndexBody } from './workflows/_crud.ts'

const OR_API = '/index.php/apps/openregister/api'
const APP_API = '/index.php/apps/opencatalogi/api'
const RUN_ID = `cvs-${Date.now()}`
const FIELD = 'zaaknummer'

/**
 * Write the schema's properties and required list.
 *
 * @param request An authenticated API request context.
 * @param schemaId The schema id.
 * @param schema The schema as read.
 * @param properties The properties to write.
 * @param required The required property names to write.
 */
async function writeSchema(
	request: APIRequestContext,
	schemaId: number,
	schema: Record<string, unknown>,
	properties: Record<string, unknown>,
	required: string[],
): Promise<void> {
	const res = await request.put(`${OR_API}/schemas/${schemaId}`, {
		data: { ...schema, properties, required },
	})
	expect(res.ok(), `writing schema ${schemaId}: ${await res.text()}`).toBe(true)
}

test.describe('A field added to the publication schema', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	let scope = { register: 0, schema: 0 }
	let path = ''
	let slug = ''
	let original: Record<string, unknown> = {}
	let originalProperties: Record<string, unknown> = {}
	let originalRequired: string[] = []
	let publicationId = ''
	const title = `${RUN_ID} Besluit met zaaknummer`

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		const catalog = await publicationCatalog(request, RUN_ID)
		path = catalog.path
		slug = catalog.slug
		const res = await request.get(`${OR_API}/schemas/${scope.schema}`)
		expect(res.ok(), 'the publication schema reads').toBe(true)
		original = await res.json()
		originalProperties = {
			...((original.properties as Record<string, unknown>) ?? {}),
		}
		originalRequired = [...((original.required as string[]) ?? [])]
		await writeSchema(
			request,
			scope.schema,
			original,
			{ ...originalProperties, [FIELD]: { type: 'string', title: FIELD } },
			originalRequired,
		)
	})

	test.afterAll(async ({ request }) => {
		await writeSchema(
			request,
			scope.schema,
			original,
			originalProperties,
			originalRequired,
		)
		if (publicationId) {
			await request
				.delete(
					`${OR_API}/objects/${scope.register}/${scope.schema}/${publicationId}`,
				)
				.catch(() => undefined)
		}
	})

	test('An administrator adds a case number', async ({
		page,
		request,
		playwright,
		baseURL,
	}) => {
		await page.goto(`${APP}${path}`)
		await dismissOverlays(page)
		await waitIndexBody(page)

		await page.locator('[data-testid="cn-cta-primary"]').first().click()
		const form = dialog(page)
		await expect(form).toBeVisible({ timeout: 10_000 })
		await expect(form.getByLabel(new RegExp(FIELD, 'i')).first()).toBeVisible()
		await fillField(form, 'Title', title)
		await fillField(form, 'Summary', 'A publication with a case number')
		await fillField(form, FIELD, 'Z-2026-00871')
		await form
			.getByRole('button', { name: /^(create|save|opslaan|aanmaken)$/i })
			.first()
			.click()
		await expect(form).toBeHidden({ timeout: 15_000 })

		const found = await request.get(
			`${OR_API}/objects/${scope.register}/${scope.schema}?_search=${encodeURIComponent(RUN_ID)}`,
		)
		const saved = (
			((await found.json()).results ?? []) as Array<Record<string, unknown>>
		).find((r) => r.title === title)
		expect(saved?.[FIELD]).toBe('Z-2026-00871')
		publicationId = String(saved?.id ?? '')

		const published = await request.post(
			`${APP_API}/publications/${publicationId}/publish`,
		)
		expect(published.ok(), await published.text()).toBe(true)

		const visitor = await playwright.request.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
		})
		const anonymous = await visitor.get(`${APP_API}/${slug}/${publicationId}`)
		expect(anonymous.ok(), await anonymous.text()).toBe(true)
		expect((await anonymous.json())[FIELD]).toBe('Z-2026-00871')
		await visitor.dispose()
	})

	test('An added field that is required', async ({ page, request }) => {
		await writeSchema(
			request,
			scope.schema,
			original,
			{ ...originalProperties, [FIELD]: { type: 'string', title: FIELD } },
			[...originalRequired, FIELD],
		)

		await page.goto(`${APP}${path}`)
		await dismissOverlays(page)
		await waitIndexBody(page)
		await page.locator('[data-testid="cn-cta-primary"]').first().click()
		const form = dialog(page)
		await expect(form).toBeVisible({ timeout: 10_000 })
		await fillField(form, 'Title', `${RUN_ID} without a case number`)
		const save = form
			.getByRole('button', { name: /^(create|save|opslaan|aanmaken)$/i })
			.first()
		if (await save.isEnabled()) {
			await save.click()
		}

		await expect(form).toBeVisible()
		await expect(form).toContainText(FIELD)
	})
})
