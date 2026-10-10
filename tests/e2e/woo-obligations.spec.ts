/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Woo-verplichtingen page, ObligationsIndex (manifest page Obligations;
 * woo-obligation-overview, REQ-WOO-001 and
 * REQ-WOO-003).
 *
 * THE FIXTURE. Two enabled `obligationSource` rows, created through
 * OpenRegister's objects API in the register and schema the settings name.
 * No app on a test box answers `ObligationsRequestedEvent` yet (dossiq's
 * listener is a sibling ask, the harvest source waits on harvest-feed-intake),
 * so both sources are unread with "No reader answered for this source.". That
 * is the behaviour under test: an unread source is named above the figures and
 * never counted as a source with nothing to publish. The counting with an
 * answering source is pinned offline in
 * tests/Unit/Service/Publication/ObligationReadServiceTest.php and
 * tests/vitest/obligationOverview.spec.js.
 *
 * Locale: nothing forces the E2E language, so texts are matched in English
 * and Dutch.
 *
 * @e2e openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#scenario-one-source-answers-and-one-fails
 * @e2e openspec/changes/woo-obligation-overview/specs/woo-compliance/spec.md#scenario-an-admin-reads-the-page
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'

const APP_BASE = '/index.php/apps/opencatalogi'
const RUN = Date.now()

/**
 * The register and schema the obligation sources live in.
 *
 * @param request The authenticated request context.
 */
async function sourceConfig(
	request: APIRequestContext,
): Promise<{ register: string; schema: string }> {
	const resp = await request.get(`${APP_BASE}/api/settings`)
	expect(resp.status(), 'GET /api/settings must succeed').toBe(200)
	const settings = await resp.json()
	return {
		register: String(
			settings?.configuration?.publication_register
				?? settings?.publication_register
				?? 'publication',
		),
		schema: String(
			settings?.configuration?.obligation_source_schema
				?? settings?.obligation_source_schema
				?? 'obligationSource',
		),
	}
}

test.describe('Woo obligations', () => {
	const created: string[] = []
	let base = ''

	test.beforeAll(async ({ request }) => {
		const { register, schema } = await sourceConfig(request)
		base = `/index.php/apps/openregister/api/objects/${register}/${schema}`
		for (const appId of [`e2e-zaken-${RUN}`, `e2e-raad-${RUN}`]) {
			const resp = await request.post(base, {
				data: { appId, title: appId, kind: 'app', enabled: true },
			})
			expect(resp.ok(), `creating source ${appId} must succeed`).toBeTruthy()
			const body = await resp.json()
			created.push(String(body?.['@self']?.id ?? body?.id ?? body?.uuid))
		}
	})

	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request.delete(`${base}/${id}`)
		}
	})

	test('the API names both sources as unread with their reason', async ({
		request,
	}) => {
		const resp = await request.get(`${APP_BASE}/api/obligations`)
		expect(resp.status()).toBe(200)
		const overview = await resp.json()
		const unread = overview.unreadSources.map(
			(row: { appId: string }) => row.appId,
		)
		expect(unread).toContain(`e2e-zaken-${RUN}`)
		expect(unread).toContain(`e2e-raad-${RUN}`)
	})

	test('ObligationsIndex: an admin reads the page with the unread sources above the figures', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/obligations`)
		await expect(
			page.getByRole('heading', {
				name: /Woo obligations|Woo-verplichtingen/,
			}),
		).toBeVisible()
		const warning = page
			.getByText(/could not be read|konden niet worden gelezen/)
			.first()
		await expect(warning).toBeVisible()
		await expect(page.getByText(`e2e-zaken-${RUN}`)).toBeVisible()
		await expect(
			page.getByText(/Still to publish|Nog te publiceren/).first(),
		).toBeVisible()
	})
})
