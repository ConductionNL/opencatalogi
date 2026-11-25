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
import type { Locator, Page } from '@playwright/test'

import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from './authenticated-request.ts'
import {
	BASE,
	Fixtures,
	REG_PUBLICATION,
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

/**
 * Scroll the detail page until a widget below the fold has mounted and shows.
 *
 * The detail grid mounts a widget when it scrolls into view.
 *
 * @param page   The page.
 * @param target The widget's element.
 */
async function scrollTo(page: Page, target: Locator): Promise<void> {
	await expect(page.locator('[gs-id]').first()).toBeAttached({ timeout: 30_000 })
	await page.mouse.move(800, 400)
	await expect(async () => {
		await page.mouse.wheel(0, 400)
		await expect(target).toBeVisible({ timeout: 500 })
	}).toPass({ timeout: 30_000 })
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
			registers: [REG_PUBLICATION],
			schemas: [SCHEMA_PUBLICATION],
		})
		const publication = await fx.createPublication(
			'signed-in-readers',
			// Published, so the anonymous 404 below is the audience at work.
			{ status: 'published', publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_PUBLICATION,
		)

		// The Visibility card starts as Everyone.
		await page.goto(`/index.php/apps/opencatalogi/publications/${slug}/${publication.id}`)
		const audience = page.getByTestId('publication-visibility-audience')
		await scrollTo(page, audience)
		await expect(audience).toContainText('Everyone')

		// The editor's save: audience and level on the publication.
		const saved = await admin.patch(
			objectPath(REG_PUBLICATION, SCHEMA_PUBLICATION, publication.id),
			{
				data: {
					audience: 'signedIn',
					minimumAssurance: 'substantial',
				},
			},
		)
		expect(saved.ok(), `save answered ${saved.status()} ${await saved.text()}`).toBe(true)
		const stored = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, publication.id)
		expect(stored?.audience).toBe('signedIn')
		expect(stored?.minimumAssurance).toBe('substantial')

		// The card reads who can read it, and that the link needs a login.
		await page.reload()
		await scrollTo(page, audience)
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
