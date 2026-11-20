/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A publication limited to readers who log in (publications-for-signed-in-readers).
 *
 * WHAT THIS SPEC IS LOOKING FOR. An editor sets "Who can read it" on a draft
 * publication without a Woo category to readers who log in, level
 * Substantial, and saves through the same object endpoint the editor's save
 * uses. The server keeps both fields, and the Visibility card on the
 * publication page reads the level in words. An anonymous reader of the
 * catalogue's public API is answered 404 for it.
 *
 * Base URL and sign-in from the environment (base-url.ts, global-setup.ts);
 * the anonymous read uses a fresh context with an empty storage state.
 *
 * @e2e openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md#scenario-an-editor-limits-a-publication-to-signed-in-readers
 * @spec openspec/changes/publications-for-signed-in-readers/specs/publications/spec.md
 */
import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'
import {
	BASE,
	Fixtures,
	REG_OPENCATALOGI,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/**
 * The OpenRegister object endpoint the editor saves through.
 *
 * @param register The register id or slug.
 * @param schema   The schema id or slug.
 * @param id       The object id.
 * @return The path.
 */
function objectPath(register: number | string, schema: number | string, id: string): string {
	return `/index.php/apps/openregister/api/objects/${register}/${schema}/${id}`
}

test.describe('Publications for signed-in readers (REQ-PSR-001)', () => {
	const fx = new Fixtures()

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('an editor limits a publication to signed-in readers', async ({
		page,
		request: admin,
	}) => {
		const slug = `psr-${Date.now().toString(36)}`
		await fx.createCatalog('signed-in-readers', {
			slug,
			registers: [REG_OPENCATALOGI],
			schemas: [SCHEMA_PUBLICATION],
		})
		const publication = await fx.createPublication(
			'signed-in-readers',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_OPENCATALOGI,
		)

		// The Visibility card starts as Everyone.
		await page.goto(`/index.php/apps/opencatalogi/publications/${slug}/${publication.id}`)
		const audience = page.getByTestId('publication-visibility-audience')
		await expect(audience).toBeVisible({ timeout: 20_000 })
		await expect(audience).toContainText('Everyone')

		// The editor's save: audience and level on the publication.
		const saved = await admin.put(
			objectPath(REG_OPENCATALOGI, SCHEMA_PUBLICATION, publication.id),
			{
				data: {
					...publication.raw,
					audience: 'signedIn',
					minimumAssurance: 'substantial',
				},
			},
		)
		expect(saved.ok(), `save answered ${saved.status()}`).toBe(true)
		const stored = await fx.fetch(REG_OPENCATALOGI, SCHEMA_PUBLICATION, publication.id)
		expect(stored?.audience).toBe('signedIn')
		expect(stored?.minimumAssurance).toBe('substantial')

		// The card reads who can read it, and that the link needs a login.
		await page.reload()
		await expect(audience).toContainText(
			'Readers who log in with DigiD or eHerkenning, level Substantial',
			{ timeout: 20_000 },
		)
		await expect(page.getByTestId('publication-visibility-audience-link')).toBeVisible()

		// An anonymous reader cannot tell it exists.
		const anonymous = await playwrightRequest.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
		})
		try {
			const read = await anonymous.get(`${API_BASE}/${slug}/${publication.id}`)
			expect(read.status()).toBe(404)
		} finally {
			await anonymous.dispose()
		}
	})
})
