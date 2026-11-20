/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Staff keep the glossary on the Glossary page (capability-rows-close-gaps,
 * REQ-CRG-002): an editor adds a term, corrects it and deletes it, and the
 * public glossary API follows each step without a publishing step.
 *
 * THE FIXTURE. One term with a run-unique title, added through the page's Add
 * form by the suite's admin account and removed by the last test (or by
 * afterAll through the API when a test failed before it).
 *
 * Base URL and the admin session come from the environment and
 * playwright.config.ts. Texts are matched in English and Dutch.
 *
 * @e2e openspec/changes/capability-rows-close-gaps/specs/content-management/spec.md#scenario-an-editor-adds-a-term
 * @e2e openspec/changes/capability-rows-close-gaps/specs/content-management/spec.md#scenario-an-editor-corrects-a-term
 */
import { expect, test } from './authenticated-request.ts'
import { dismissOverlays } from './spec-coverage/_nav.ts'
import { createViaForm, dialog, fillField, rowAction, rowByTitle, waitIndexBody } from './workflows/_crud.ts'

const APP = '/index.php/apps/opencatalogi'
const API = `${APP}/api/glossary`

const RUN = `crg-${Date.now()}`
const TERM = `Woo-verzoek ${RUN}`
const SUMMARY = 'Een verzoek om informatie over een bestuurlijke aangelegenheid.'
const CORRECTED = 'Een verzoek om publieke informatie bij een bestuursorgaan.'

/**
 * The term as the public glossary API answers it, or undefined.
 *
 * @param request The request context.
 * @return The term.
 */
async function publicTerm(request): Promise<Record<string, unknown> | undefined> {
	const response = await request.get(`${API}?_search=${encodeURIComponent(RUN)}`, {
		headers: { Accept: 'application/json' },
	})
	expect(response.ok(), await response.text()).toBe(true)
	const body = await response.json()
	const results = (body.results ?? body) as Array<Record<string, unknown>>
	return results.find((term) => term.title === TERM)
}

test.describe.serial('Glossary page', () => {
	test.afterAll(async ({ request }) => {
		const term = await publicTerm(request).catch(() => undefined)
		const id = term?.id ?? (term?.['@self'] as Record<string, unknown> | undefined)?.id
		if (id) {
			await request.delete(`/index.php/apps/openregister/api/objects/publication/glossary/${id}`)
		}
	})

	test('an editor adds a term', async ({ page, request }) => {
		await page.goto(`${APP}/glossary`)
		await dismissOverlays(page)
		await waitIndexBody(page)

		await createViaForm(page, TERM, { Summary: SUMMARY })

		await expect(rowByTitle(page, TERM)).toBeVisible({ timeout: 15000 })
		await expect.poll(async () => (await publicTerm(request))?.title, { timeout: 15000 }).toBe(TERM)
	})

	test('an editor corrects a term', async ({ page, request }) => {
		await page.goto(`${APP}/glossary`)
		await dismissOverlays(page)
		await waitIndexBody(page)

		await rowAction(page, TERM, 'edit')
		const form = dialog(page)
		await expect(form).toBeVisible({ timeout: 10000 })
		await fillField(form, 'Summary', CORRECTED)
		await form.getByRole('button', { name: /^(save|opslaan)$/i }).first().click()
		await expect(form).toBeHidden({ timeout: 15000 })

		await expect.poll(async () => (await publicTerm(request))?.summary, { timeout: 15000 }).toBe(CORRECTED)
	})

	test('an editor deletes a term', async ({ page, request }) => {
		await page.goto(`${APP}/glossary`)
		await dismissOverlays(page)
		await waitIndexBody(page)

		await rowAction(page, TERM, 'delete')
		const confirm = dialog(page)
		await expect(confirm).toBeVisible({ timeout: 10000 })
		await confirm.getByRole('button', { name: /^(delete|verwijderen)$/i }).first().click()
		await expect(confirm).toBeHidden({ timeout: 15000 })

		await expect(rowByTitle(page, TERM)).toBeHidden({ timeout: 15000 })
		await expect.poll(async () => publicTerm(request), { timeout: 15000 }).toBeUndefined()
	})
})
