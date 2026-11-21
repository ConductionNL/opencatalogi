/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An editor creates a publication from a catalogue's Publications page with a
 * title, summary, description and theme (REQ-CRR-001).
 *
 * WHAT THIS SPEC IS LOOKING FOR. Add on the Publications page saves a draft
 * that the catalogue lists, carrying the summary, the description and the
 * picked theme; a form without a title is refused and names the field.
 *
 * WHAT IT CREATES AND REMOVES. One catalogue (through publicationCatalog), one
 * theme "Welzijn <run>" and the publication the form saves. The theme and the
 * publication are deleted in afterAll.
 *
 * @e2e openspec/changes/capability-rows-get-requirements/specs/publications/spec.md#scenario-an-editor-adds-a-publication
 * @e2e openspec/changes/capability-rows-get-requirements/specs/publications/spec.md#scenario-a-publication-without-a-title-is-not-saved
 * @spec openspec/changes/capability-rows-get-requirements/specs/publications/spec.md
 */
import { expect, test } from '@playwright/test'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'
import { dialog, fillField, waitIndexBody } from './workflows/_crud.ts'

const OR_API = '/index.php/apps/openregister/api'
const RUN_ID = `crr-${Date.now()}`

test.describe('Creating a publication', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	const title = `${RUN_ID} Besluit subsidie buurthuis`
	const themeTitle = `Welzijn ${RUN_ID}`
	let path = ''
	let scope = { register: 0, schema: 0 }
	let themeId = ''
	let publicationId = ''

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		path = (await publicationCatalog(request, RUN_ID)).path
		const theme = await request.post(`${OR_API}/objects/publication/theme`, {
			data: { title: themeTitle, summary: 'Theme fixture' },
		})
		expect(theme.ok(), `seed theme: ${await theme.text()}`).toBe(true)
		themeId = String((await theme.json()).id)
	})

	test.afterAll(async ({ request }) => {
		if (publicationId) {
			await request
				.delete(
					`${OR_API}/objects/${scope.register}/${scope.schema}/${publicationId}`,
				)
				.catch(() => undefined)
		}
		if (themeId) {
			await request
				.delete(`${OR_API}/objects/publication/theme/${themeId}`)
				.catch(() => undefined)
		}
	})

	test('An editor adds a publication', async ({ page, request }) => {
		await page.goto(`${APP}${path}`)
		await dismissOverlays(page)
		await waitIndexBody(page)

		await page.locator('[data-testid="cn-cta-primary"]').first().click()
		const form = dialog(page)
		await expect(form).toBeVisible({ timeout: 10_000 })
		await fillField(form, 'Title', title)
		await fillField(
			form,
			'Summary',
			'Subsidie voor het buurthuis in de Oostwijk',
		)
		await fillField(form, 'Description', 'Het college kent een subsidie toe.')
		await form
			.getByLabel(/themes|thema/i)
			.first()
			.click()
		await page.getByRole('option', { name: themeTitle }).first().click()
		await form
			.getByRole('button', { name: /^(create|save|opslaan|aanmaken)$/i })
			.first()
			.click()
		await expect(form).toBeHidden({ timeout: 15_000 })

		const listed = page.locator('main tbody tr').filter({ hasText: title })
		await expect(listed).toBeVisible({ timeout: 30_000 })
		await expect(listed).toContainText(/draft|concept/i)

		const found = await request.get(
			`${OR_API}/objects/${scope.register}/${scope.schema}?_search=${encodeURIComponent(RUN_ID)}`,
		)
		expect(found.ok()).toBe(true)
		const results = ((await found.json()).results ?? []) as Array<
			Record<string, unknown>
		>
		const saved = results.find((r) => r.title === title)
		expect(saved, 'the saved publication').toBeTruthy()
		publicationId = String(saved?.id ?? '')
		expect(saved?.summary).toBe('Subsidie voor het buurthuis in de Oostwijk')
		expect(saved?.description).toBe('Het college kent een subsidie toe.')
		expect((saved?.themes as unknown[]).map(String)).toContain(themeId)
	})

	test('A publication without a title is not saved', async ({ page }) => {
		await page.goto(`${APP}${path}`)
		await dismissOverlays(page)
		await waitIndexBody(page)

		await page.locator('[data-testid="cn-cta-primary"]').first().click()
		const form = dialog(page)
		await expect(form).toBeVisible({ timeout: 10_000 })
		await fillField(form, 'Summary', 'A summary without a title')
		const save = form
			.getByRole('button', { name: /^(create|save|opslaan|aanmaken)$/i })
			.first()
		if (await save.isEnabled()) {
			await save.click()
		}

		await expect(form).toBeVisible()
		await expect(form).toContainText(/title|titel/i)
		await expect(form).toContainText(/required|verplicht/i)
	})
})
