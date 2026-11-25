/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The publish dialog says what is needed to publish (REQ-PRP-003).
 *
 * WHAT THIS SPEC IS LOOKING FOR. An editor selects two draft publications on
 * the publications list, one without a summary, and opens Publish. Before they
 * confirm, the dialog names the incomplete one with "summary" and the button
 * reads "Publish 1 of 2 publications". Confirming publishes only the complete
 * one; the incomplete one stays a draft.
 *
 * The field names come from the publication schema's lifecycle
 * (`states.published.fields.required`). While that declaration is held back
 * (publication-required-at-publish task 1.1) the dialog has nothing to list,
 * so the test is skipped with that reason instead of failing on it.
 *
 * @e2e openspec/changes/publication-required-at-publish/specs/publications/spec.md#scenario-two-selected-one-incomplete REQ-PRP-003
 */
import type { Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

const OR_API = '/index.php/apps/openregister/api'
const RUN_ID = `prp-${Date.now()}`

test.describe('Publication required at publish', () => {
	test.setTimeout(120_000)

	const complete = `${RUN_ID} Budget 2027`
	const incomplete = `${RUN_ID} Council papers for 15 October`
	const created = new Map<string, string>()
	let scope = { register: 0, schema: 0 }
	let path = ''
	let declared = false

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		path = (await publicationCatalog(request, RUN_ID)).path

		const schema = await (
			await request.get(`${OR_API}/schemas/${scope.schema}`)
		).json()
		const lifecycle =
			schema?.['x-openregister-lifecycle']
			?? schema?.configuration?.['x-openregister-lifecycle']
		declared = JSON.stringify(
			lifecycle?.states?.published?.fields?.required ?? [],
		).includes('summary')

		const seeds: Array<[string, Record<string, unknown>]> = [
			[
				complete,
				{ summary: 'Begroting', description: 'De begroting voor 2027.' },
			],
			[incomplete, { description: 'Agenda en stukken.' }],
		]
		for (const [title, fields] of seeds) {
			const res = await request.post(
				`${OR_API}/objects/${scope.register}/${scope.schema}`,
				{
					data: { title, status: 'draft', ...fields },
				},
			)
			expect(res.ok(), `seed ${title}`).toBe(true)
			created.set(title, String((await res.json()).id))
		}
	})

	test.afterAll(async ({ request }) => {
		for (const id of created.values()) {
			await request
				.delete(`${OR_API}/objects/${scope.register}/${scope.schema}/${id}`)
				.catch(() => undefined)
		}
	})

	/**
	 * Tick the row of a publication on the list.
	 *
	 * @param page The page.
	 * @param title The publication title.
	 */
	async function select(page: Page, title: string): Promise<void> {
		await page
			.locator('main tbody tr')
			.filter({ hasText: title })
			.locator('input[type="checkbox"]')
			.first()
			.check({ force: true })
	}

	test('Two selected, one incomplete', async ({ page, request }) => {
		test.skip(
			!declared,
			'The publication schema declares no fields required to publish yet (task 1.1 held back).',
		)

		await page.goto(`${APP}${path}&_search=${encodeURIComponent(RUN_ID)}`)
		await dismissOverlays(page)
		await select(page, complete)
		await select(page, incomplete)
		await page.locator('[data-testid="cn-bulk-action-publish"]').first().click()

		const dialog = page.getByRole('dialog')
		await expect(dialog.getByTestId('publish-incomplete')).toContainText(
			`${incomplete}: summary`,
		)
		const confirm = dialog.getByRole('button', {
			name: /Publish 1 of 2 publications|1 van 2 publicaties publiceren/i,
		})
		await expect(confirm).toBeEnabled()
		await confirm.click()

		await expect
			.poll(async () => {
				const res = await request.get(
					`${OR_API}/objects/${scope.register}/${scope.schema}/${created.get(complete)}`,
				)
				return (await res.json()).status
			})
			.toBe('published')
		const left = await request.get(
			`${OR_API}/objects/${scope.register}/${scope.schema}/${created.get(incomplete)}`,
		)
		expect((await left.json()).status).toBe('draft')
	})
})
