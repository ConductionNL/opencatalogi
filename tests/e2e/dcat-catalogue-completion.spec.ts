/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The licence comes from a controlled list (REQ-DCC-001).
 *
 * WHAT THIS SPEC IS LOOKING FOR. An administrator on the DCAT settings opens
 * the default licence field and picks a licence from the EU licences list; the
 * setting then holds that licence's authority IRI. A value outside the list
 * cannot be typed in the field, and the settings endpoint refuses it when it is
 * sent anyway, keeping the stored default.
 *
 * The base URL comes from NEXTCLOUD_URL (base-url.ts).
 *
 * @e2e openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#scenario-an-editor-picks-a-licence REQ-DCC-001
 */
import { expect, test } from './authenticated-request.ts'
import { publicationScope } from './publication-catalog.ts'

/** The admin settings page of the app. */
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/** OpenRegister's object API. */
const OR_API = '/index.php/apps/openregister/api'

/** The setting the default licence field writes. */
const KEY = 'dcat_default_license'

test.describe('DCAT catalogue completion', () => {
	test('an editor picks a licence', async ({ request: admin, page }) => {
		const before = await admin.get(`${API_BASE}/settings`)
		expect(before.ok()).toBe(true)
		const original = String((await before.json())?.configuration?.[KEY] ?? '')

		const list = await admin.get(`${API_BASE}/dcat/licences`)
		expect(list.ok(), `licences: ${await list.text()}`).toBe(true)
		const rows = ((await list.json())?.results ?? []) as Array<{ iri: string; label: string }>
		expect(rows.length).toBeGreaterThan(1)
		const chosen = rows.find((row) => row.iri !== original) ?? rows[0]

		try {
			await page.goto(SETTINGS_PAGE)
			const block = page.getByTestId('dcat-default-licence')
			await block.scrollIntoViewIfNeeded()
			const field = block.getByRole('combobox')
			await field.click()
			await field.fill('vrij te gebruiken')
			// NcSelect options carry no accessible name of their own: find them by their text.
			await expect(page.getByRole('option').filter({ hasText: 'vrij te gebruiken' })).toHaveCount(0)
			await field.fill(chosen.label)
			await page.getByRole('option').filter({ hasText: chosen.label }).first().click()
			await expect(block.getByTestId('dcat-default-licence-message')).toContainText('Default licence saved.')

			const after = await admin.get(`${API_BASE}/settings`)
			expect((await after.json())?.configuration?.[KEY]).toBe(chosen.iri)

			const refused = await admin.put(`${API_BASE}/settings`, { data: { [KEY]: 'vrij te gebruiken' } })
			expect(refused.status()).toBe(400)
			const kept = await admin.get(`${API_BASE}/settings`)
			expect((await kept.json())?.configuration?.[KEY]).toBe(chosen.iri)
		} finally {
			await admin.put(`${API_BASE}/settings`, { data: { [KEY]: original } })
		}
	})

	/**
	 * The publication's own licence (task 1.4): the schema declares `license` as a field
	 * from the EU licences list, a listed licence is stored as its authority IRI, and a
	 * value outside the list is refused on save, naming the field.
	 */
	test('a publication takes its licence from the list', async ({ request }) => {
		const scope = await publicationScope(request)
		const schema = await (await request.get(`${OR_API}/schemas/${scope.schema}`)).json()
		expect(schema?.properties?.license?.['x-value-list']).toBe('licence')

		const rows = ((await (await request.get(`${API_BASE}/dcat/licences`)).json())?.results ?? []) as Array<{ iri: string }>
		expect(rows.length).toBeGreaterThan(0)

		const objects = `${OR_API}/objects/${scope.register}/${scope.schema}`
		const listed = await request.post(objects, {
			data: { title: `dcc-${Date.now()} licensed`, status: 'draft', license: rows[0].iri },
		})
		expect(listed.ok(), `listed: ${await listed.text()}`).toBe(true)
		const id = String((await listed.json()).id)

		try {
			const unlisted = await request.post(objects, {
				data: { title: `dcc-${Date.now()} unlisted`, status: 'draft', license: 'vrij te gebruiken' },
			})
			expect(unlisted.ok()).toBe(false)
			expect(await unlisted.text()).toMatch(/licen[cs]e/i)
			expect((await (await request.get(`${objects}/${id}`)).json())?.license).toBe(rows[0].iri)
		} finally {
			await request.delete(`${objects}/${id}`).catch(() => undefined)
		}
	})
})
