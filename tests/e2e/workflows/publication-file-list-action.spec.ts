/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Publications index "File list" row action (opencatalogi#1583): from a
 * publication's row menu, File list opens the publication in the viewObject
 * modal on its Files tab, with the Add File button to upload another.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test workflows/publication-file-list-action
 */
import { expect, test } from '@playwright/test'
import {
	bootApp,
	fatalErrors,
	navToRoute,
	trackPageErrors,
} from '../spec-coverage/_nav.ts'
import { rowAction, waitIndexBody } from './_crud.ts'
import { Fixtures } from './_fixtures.ts'

const fx = new Fixtures()

test.beforeAll(async () => {
	await fx.init()
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('publication File list row action', () => {
	test('Publication — the File list row action opens the publication on its Files tab', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		const slug = `file-list-${Date.now()}`
		await fx.createCatalog('File list Catalog', { slug })
		const pub = await fx.createPublication('File list Publication')

		await bootApp(page)
		await navToRoute(page, `/publications/${slug}`)
		await waitIndexBody(page)

		await rowAction(page, pub.title, 'file-list')

		const dialog = page
			.getByRole('dialog')
			.filter({ has: page.locator('.viewObjectDialog') })
		await expect(dialog).toBeVisible({ timeout: 15000 })
		// The tab's name also carries its file-count bubble.
		await expect(
			dialog.getByRole('tab', { name: /^\s*Files\b/ }),
		).toHaveAttribute('aria-selected', 'true')
		await expect(
			dialog.getByRole('button', { name: 'Add File', exact: true }),
		).toBeVisible()

		expect(fatalErrors(errors)).toHaveLength(0)
	})
})
