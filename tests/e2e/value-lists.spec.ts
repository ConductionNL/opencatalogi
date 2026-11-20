/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The controlled lists for integrators, and where the refusal grounds come
 * from (woo-value-lists-on-the-concept-register).
 *
 * An integrator with no account filters the lists on documentsoorten and gets
 * each entry's code, label, URI and status. Without dossiq the Woo settings
 * say the grounds are a fixed copy maintained in dossiq, and the officer's
 * grounds list still offers 5.1.2.e for a redacted passage.
 *
 * @e2e openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#scenario-an-integrator-copies-a-uri
 * @e2e openspec/changes/woo-value-lists-on-the-concept-register/specs/woo-compliance/spec.md#scenario-without-dossiq-grounds-can-be-picked-for-redaction-and-not-edited
 */
import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'

const API_BASE = '/index.php/apps/opencatalogi/api'
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'

test.describe('Value lists for integrators', () => {
	test('an integrator with no account filters on documentsoorten and gets each URI', async ({
		baseURL,
	}) => {
		// The least privileged caller: no cookies, no credentials.
		const anonymous = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: undefined,
		})

		const response = await anonymous.get(`${API_BASE}/value-lists?scheme=documentsoorten`, {
			headers: { Origin: 'https://integrator.example.org' },
		})
		expect(response.status(), await response.text()).toBe(200)

		const body = await response.json()
		expect(body.schemes).toEqual(['documentsoorten'])
		expect(body.results.length).toBeGreaterThan(0)
		for (const entry of body.results) {
			expect(entry.scheme).toBe('documentsoorten')
			expect(entry.code).not.toBe('')
			expect(entry.label).not.toBe('')
			expect(entry.uri).toMatch(/^https:\/\/identifier\.overheid\.nl\/tooi\//)
			expect(['active', 'deprecated']).toContain(entry.status)
		}

		await anonymous.dispose()
	})

	test('the JSON lists every scheme OpenCatalogi uses, each entry with a URI', async ({
		baseURL,
	}) => {
		const anonymous = await playwrightRequest.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: undefined,
		})

		const body = await (await anonymous.get(`${API_BASE}/value-lists`)).json()
		const schemes = new Set(body.results.map((entry: { scheme: string }) => entry.scheme))
		for (const scheme of body.schemes) {
			if (!body.unavailable.includes(scheme)) {
				expect(schemes.has(scheme), `no entries for ${scheme}`).toBe(true)
			}
		}
		for (const entry of body.results) {
			expect(entry.uri, `${entry.scheme} ${entry.code}`).toMatch(/^https?:\/\//)
		}

		await anonymous.dispose()
	})

	test.fixme('the public value-lists page shows a copy button per URI', async ({ page }) => {
		// The page /apps/opencatalogi/value-lists is not built yet; the JSON
		// twin above is. Unfix once the page ships.
		await page.goto('/index.php/apps/opencatalogi/value-lists')
		await page.getByRole('combobox').selectOption('documentsoorten')
		await expect(page.getByRole('button', { name: /copy/i }).first()).toBeVisible()
	})
})

test.describe('Refusal grounds without dossiq', () => {
	test('the settings say the grounds are a fixed copy, and 5.1.2.e can be cited', async ({
		page,
		request: admin,
	}) => {
		const grounds = await (await admin.get(`${API_BASE}/woo/weigeringsgronden`)).json()
		const rows = (grounds.results ?? []) as Array<{ article: string, source: string }>
		test.skip(
			rows.some((row) => row.source === 'dossiq'),
			'dossiq is installed here, so the grounds are dossiq\'s and no snapshot is in use',
		)

		// The officer's list, which the assessment dialog reads, offers 5.1.2.e.
		expect(rows.map((row) => row.article)).toContain('5.1.2.e')
		expect(rows.every((row) => row.source === 'snapshot')).toBe(true)

		await page.goto(SETTINGS_PAGE)
		const note = page.getByTestId('refusal-grounds-snapshot')
		await expect(note).toBeVisible()
		await expect(note).toContainText('dossiq')
	})
})
