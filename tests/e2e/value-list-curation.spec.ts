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
 * @e2e openspec/changes/woo-value-list-curation/specs/woo-compliance/spec.md#scenario-an-administrator-puts-the-most-used-category-first
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
