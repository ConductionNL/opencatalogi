/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deleting one publication from the Publications page asks in a dialog that
 * says what disappears from the public side (board OcPublicatieVerwijderen),
 * and a record of another schema in the same catalog keeps the library's
 * generic delete dialog.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The words of the single-delete dialog per
 * row, and that Cancel deletes nothing. The delete itself is OpenRegister's
 * and covered there, so no test here confirms one.
 *
 * Base URL and credentials come from the environment (playwright.config.ts);
 * the seeded catalog holds the publication schema and, where the instance has
 * it, the publiccode schema.
 *
 * @e2e openspec/changes/publication-delete-says-what-it-removes/specs/publication-delete/spec.md#scenario-the-editor-reads-the-consequence-before-deleting
 * @e2e openspec/changes/publication-delete-says-what-it-removes/specs/publication-delete/spec.md#scenario-cancel-keeps-the-publication
 * @e2e openspec/changes/publication-delete-says-what-it-removes/specs/publication-delete/spec.md#scenario-a-publiccode-record-gets-the-generic-text
 */
import type { Page } from '@playwright/test'
import type { SeededObject } from './workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import { bootApp, navToRoute } from './spec-coverage/_nav.ts'
import { dialog, rowAction, rowByTitle, waitIndexBody } from './workflows/_crud.ts'
import {
	Fixtures,
	REG_PUBLICATION,
	SCHEMA_PUBLICATION,
	SCHEMA_PUBLICCODE,
} from './workflows/_fixtures.ts'

const fx = new Fixtures()

const TITLE = /^\s*(Delete publication|Publicatie verwijderen)\s*$/
const GENERIC_TITLE = /^\s*(Delete item|Item verwijderen)\s*$/
const CONSEQUENCE = /Woo[ -]sitemap/
const CANCEL = /^(Cancel|Annuleren)$/

let slug: string
let publication: SeededObject
let component: SeededObject | null = null

/**
 * Open the catalog's publications page on the publication pair.
 *
 * @param page The page.
 */
async function openPublications(page: Page): Promise<void> {
	await bootApp(page)
	await navToRoute(
		page,
		`/publications/${encodeURIComponent(slug)}?_pair=${REG_PUBLICATION}-${SCHEMA_PUBLICATION}`,
	)
	await waitIndexBody(page)
}

test.beforeAll(async () => {
	await fx.init()
	slug = `${fx.prefix}-delete`
	const schemas = SCHEMA_PUBLICCODE === null
		? [SCHEMA_PUBLICATION]
		: [SCHEMA_PUBLICATION, SCHEMA_PUBLICCODE]
	await fx.createCatalog('Delete dialog', {
		slug,
		registers: [REG_PUBLICATION],
		schemas,
	})
	publication = await fx.createPublication(
		'Woo decision on the swimming pool tender',
	)
	if (SCHEMA_PUBLICCODE !== null) {
		component = await fx.createPubliccode('Signalen', {
			softwareType: 'standalone/web',
		})
	}
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('deleting one publication', () => {
	test('the dialog says what disappears from the public side', async ({
		page,
	}) => {
		await openPublications(page)
		await rowAction(page, publication.title, 'delete')

		const confirm = dialog(page)
		await expect(confirm).toBeVisible({ timeout: 10000 })
		await expect(confirm.getByRole('heading').first()).toHaveText(TITLE)
		await expect(confirm).toContainText(publication.title)
		await expect(confirm).toContainText(
			/cannot be undone|niet ongedaan worden gemaakt/,
		)
		await expect(
			confirm.locator('[data-testid="cn-delete-description"]'),
		).toContainText(CONSEQUENCE)
	})

	test('Cancel keeps the publication in the list', async ({ page }) => {
		await openPublications(page)
		await rowAction(page, publication.title, 'delete')

		const confirm = dialog(page)
		await expect(confirm).toBeVisible({ timeout: 10000 })
		await confirm.getByRole('button', { name: CANCEL }).first().click()
		await expect(page.getByRole('dialog')).toHaveCount(0)
		await expect(rowByTitle(page, publication.title)).toBeVisible()

		const stored = await fx.fetch(
			REG_PUBLICATION,
			SCHEMA_PUBLICATION,
			publication.id,
		)
		expect(stored, 'the publication is still stored').toBeTruthy()
	})

	test('a publiccode record in the same catalog gets the generic dialog', async ({
		page,
	}) => {
		test.skip(component === null, 'this instance has no publiccode schema')
		await openPublications(page)
		await rowAction(page, component!.title, 'delete')

		const confirm = dialog(page)
		await expect(confirm).toBeVisible({ timeout: 10000 })
		await expect(confirm.getByRole('heading').first()).toHaveText(
			GENERIC_TITLE,
		)
		await expect(confirm).not.toContainText(CONSEQUENCE)
		await confirm.getByRole('button', { name: CANCEL }).first().click()
		await expect(rowByTitle(page, component!.title)).toBeVisible()
	})
})
