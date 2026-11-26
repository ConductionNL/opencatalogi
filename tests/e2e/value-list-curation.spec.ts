/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation curates how it uses the national value lists (woo-value-list-curation).
 *
 * An administrator moves "Subsidieverplichtingen anders dan met beschikking" to
 * the top of the information categories with the keyboard, the live region says
 * where it is, and the category list the officer pickers read starts with it.
 * An explanation written in the same section is served by the public category
 * list. The stored order and explanation are restored afterwards.
 *
 * A water board sets its organisation type: the meeting documents of the
 * Staten-Generaal are proposed with their legal basis, and confirming the
 * proposal takes them out of the officer's category list. The type and the
 * hidden flag are restored afterwards.
 *
 * @e2e openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#scenario-an-administrator-puts-the-most-used-category-first
 * An administrator activates gemeente Dordrecht and gemeente Zwijndrecht out of
 * the TOOI register in the settings; the activated set officers are offered is
 * exactly those two. The activations before the test are restored afterwards.
 *
 * @e2e openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#scenario-a-water-board-hides-the-categories-it-never-publishes
 * @e2e openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#scenario-only-activated-organisations-are-offered
 */
import { expect, test } from './authenticated-request.ts'

const API_BASE = '/index.php/apps/opencatalogi/api'
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'
const SCHEME = 'informatiecategorieen'
const SUBSIDIES = 'infocat013'
const COVENANTS = 'infocat011'

test.describe('Value list curation (REQ-WVL-002)', () => {
	let order: string[] = []
	let covenantsUri = ''
	let covenantsExplanation = ''

	test.beforeAll(async ({ request: admin }) => {
		const list = await (
			await admin.get(`${API_BASE}/woo/value-list-choices?scheme=${SCHEME}`)
		).json()
		order = (list.entries ?? []).map((entry: { uri: string }) => entry.uri)
		const covenants = (list.entries ?? []).find(
			(entry: { code: string }) => entry.code === COVENANTS,
		)
		covenantsUri = covenants?.uri ?? ''
		covenantsExplanation = covenants?.explanation ?? ''
	})

	test.afterAll(async ({ request: admin }) => {
		if (order.length > 0) {
			await admin.put(`${API_BASE}/woo/value-list-choices/order`, {
				data: { scheme: SCHEME, entries: order },
			})
		}
		if (covenantsUri !== '') {
			await admin.put(`${API_BASE}/woo/value-list-choices`, {
				data: {
					scheme: SCHEME,
					entry: covenantsUri,
					choice: { explanation: covenantsExplanation },
				},
			})
		}
	})

	test('an administrator puts the most used category first', async ({
		page,
		request: admin,
	}) => {
		// Start from a known order with the category last, so the moves are made
		// whatever order the instance had (afterAll puts the original back).
		const list = await (
			await admin.get(`${API_BASE}/woo/value-list-choices?scheme=${SCHEME}`)
		).json()
		const entries: Array<{ uri: string, code: string }> = list.entries ?? []
		const subsidies = entries.find((entry) => entry.code === SUBSIDIES)
		expect(subsidies, `${SUBSIDIES} is in the list`).toBeTruthy()
		const start = await admin.put(`${API_BASE}/woo/value-list-choices/order`, {
			data: {
				scheme: SCHEME,
				entries: [
					...entries.filter((entry) => entry.code !== SUBSIDIES).map((entry) => entry.uri),
					subsidies!.uri,
				],
			},
		})
		expect(start.ok(), 'the starting order is saved').toBe(true)

		await page.goto(SETTINGS_PAGE)
		const section = page.getByTestId('category-explanations')
		await expect(section).toBeVisible()

		const up = section.getByTestId(`category-move-up-${SUBSIDIES}`)
		for (let i = 0; i < 20 && (await up.isEnabled()); i++) {
			const saved = page.waitForResponse(
				(response) =>
					response.url().includes('/value-list-choices/order')
					&& response.request().method() === 'PUT',
			)
			await up.focus()
			await page.keyboard.press('Enter')
			expect((await saved).ok()).toBe(true)
		}

		await expect(up).toBeDisabled()
		await expect(page.getByTestId('category-order-announcement')).toContainText(
			/1/,
		)

		const categories = await (
			await admin.get(`${API_BASE}/woo/categories`)
		).json()
		expect(categories.results[0].code).toBe(SUBSIDIES)
	})

	test('an explanation is served by the public category list', async ({
		page,
		request: admin,
	}) => {
		const explanation =
			'Afspraken die de gemeente schriftelijk maakt met andere partijen.'
		await page.goto(SETTINGS_PAGE)
		const row = page.getByTestId(`category-explanation-${COVENANTS}`)
		await row.getByRole('textbox').fill(explanation)
		const saved = page.waitForResponse(
			(response) =>
				response.url().endsWith('/value-list-choices')
				&& response.request().method() === 'PUT',
		)
		await row
			.getByRole('button', { name: /Save explanation|Toelichting opslaan/ })
			.click()
		expect((await saved).ok()).toBe(true)

		const publicList = await (
			await admin.get(`${API_BASE}/woo/categories/public`)
		).json()
		const covenants = publicList.results.find(
			(entry: { code: string }) => entry.code === COVENANTS,
		)
		expect(covenants.explanation).toBe(explanation)
	})
})

test.describe('Organisation type proposal (REQ-WVL-004)', () => {
	const STATES_GENERAL = 'infocat007'
	let previousType: string | null = null
	let statesGeneralUri = ''
	let statesGeneralHidden = false

	test.beforeAll(async ({ request: admin }) => {
		previousType = (
			await (await admin.get(`${API_BASE}/woo/organisation-type`)).json()
		).type
		const list = await (
			await admin.get(`${API_BASE}/woo/value-list-choices?scheme=${SCHEME}`)
		).json()
		const entry = (list.entries ?? []).find(
			(candidate: { code: string }) => candidate.code === STATES_GENERAL,
		)
		statesGeneralUri = entry?.uri ?? ''
		statesGeneralHidden = entry?.hidden === true
	})

	test.afterAll(async ({ request: admin }) => {
		if (previousType !== null) {
			await admin.put(`${API_BASE}/woo/organisation-type`, {
				data: { type: previousType },
			})
		}
		if (statesGeneralUri !== '') {
			await admin.put(`${API_BASE}/woo/value-list-choices`, {
				data: {
					scheme: SCHEME,
					entry: statesGeneralUri,
					choice: { hidden: statesGeneralHidden },
				},
			})
		}
	})

	test('a water board hides the categories it never publishes', async ({
		page,
		request: admin,
	}) => {
		const set = await admin.put(`${API_BASE}/woo/organisation-type`, {
			data: { type: 'waterschap' },
		})
		expect(set.ok()).toBe(true)

		await page.goto(SETTINGS_PAGE)
		const section = page.getByTestId('organisation-type')
		await expect(section).toBeVisible()
		const proposed = section.getByTestId(
			`organisation-type-proposal-${STATES_GENERAL}`,
		)
		await expect(proposed).toContainText(/artikel 3\.3/)
		await expect(proposed.getByRole('checkbox')).toBeChecked()

		const hidden = page.waitForResponse(
			(response) =>
				response.url().endsWith('/value-list-choices')
				&& response.request().method() === 'PUT',
		)
		await section.getByTestId('organisation-type-hide').click()
		expect((await hidden).ok()).toBe(true)
		await expect(section.getByTestId('organisation-type-done')).toBeVisible()

		const categories = await (
			await admin.get(`${API_BASE}/woo/categories`)
		).json()
		expect(
			categories.results.map((entry: { code: string }) => entry.code),
		).not.toContain(STATES_GENERAL)
	})
})

test.describe('Activated organisations (REQ-WVL-005)', () => {
	const DORDRECHT = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0505'
	const ZWIJNDRECHT = 'https://identifier.overheid.nl/tooi/id/gemeente/gm0642'
	let before: string[] = []

	test.beforeAll(async ({ request: admin }) => {
		const active = await (await admin.get(`${API_BASE}/woo/organisations`)).json()
		before = (active.results ?? []).map((row: { uri: string }) => row.uri)
		for (const uri of before) {
			await admin.put(`${API_BASE}/woo/organisations/activation`, {
				data: { entry: uri, active: false },
			})
		}
	})

	test.afterAll(async ({ request: admin }) => {
		for (const uri of [DORDRECHT, ZWIJNDRECHT]) {
			await admin.put(`${API_BASE}/woo/organisations/activation`, {
				data: { entry: uri, active: before.includes(uri) },
			})
		}
		for (const uri of before) {
			await admin.put(`${API_BASE}/woo/organisations/activation`, {
				data: { entry: uri, active: true },
			})
		}
	})

	test('only activated organisations are offered', async ({
		page,
		request: admin,
	}) => {
		await page.goto(SETTINGS_PAGE)
		const section = page.getByTestId('activated-organisations')
		await expect(section).toBeVisible()
		await expect(section.getByTestId('activated-organisations-empty')).toBeVisible()

		for (const [name, code] of [['Dordrecht', 'gm0505'], ['Zwijndrecht', 'gm0642']]) {
			// NcTextField puts the testid on the input itself, so the field is found by its label.
			await section.getByRole('textbox', { name: 'Search by name or code' }).fill(name)
			await section.getByTestId('activated-organisations-search').click()
			const row = section.getByTestId(`activated-organisation-${code}`)
			await expect(row).toBeVisible()
			const saved = page.waitForResponse(
				(response) =>
					response.url().endsWith('/woo/organisations/activation')
					&& response.request().method() === 'PUT',
			)
			// nc-vue's switch label lies over the input and takes the pointer, so the switch is toggled by keyboard.
			await row.getByRole('switch').focus()
			await page.keyboard.press('Space')
			expect((await saved).ok()).toBe(true)
		}

		const offered = await (await admin.get(`${API_BASE}/woo/organisations/active`)).json()
		expect(
			offered.results.map((row: { uri: string }) => row.uri).sort(),
		).toEqual([DORDRECHT, ZWIJNDRECHT].sort())
	})
})
