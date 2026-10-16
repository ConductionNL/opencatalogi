/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Objects of a schema other than the publication schema on a catalog's
 * publications page (opencatalogi#1932), with a catalog over the publiccode
 * schema as the case: the default columns, the filter and columns sidebar, and
 * the generic detail page that shows a component's description per language.
 * Needs the OpenCatalogi settings of this change imported, so the publiccode
 * schema carries its `widget` and summary configuration.
 *
 * @e2e openspec/specs/publications/spec.md#scenario-a-components-description-can-be-read-per-language
 * @e2e openspec/specs/publications/spec.md#scenario-a-ctrl-click-opens-the-component-in-a-new-tab
 * @e2e openspec/specs/publications/spec.md#scenario-the-component-is-edited-in-the-form-with-its-description
 * @e2e openspec/specs/publications/spec.md#scenario-the-sidebar-is-offered-on-a-catalog-of-components
 * @e2e openspec/specs/publications/spec.md#scenario-the-componenten-catalog-lists-three-columns
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test workflows/catalog-other-schema-objects
 */
import type { SeededObject } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	bootApp,
	fatalErrors,
	navToRoute,
	trackPageErrors,
} from '../spec-coverage/_nav.ts'
import { rowByTitle, waitIndexBody } from './_crud.ts'
import { Fixtures, REG_PUBLICATION, SCHEMA_PUBLICCODE } from './_fixtures.ts'

const fx = new Fixtures()

const DESCRIPTION = {
	nl: {
		shortDescription: 'Meldingen over de openbare ruimte',
		longDescription: 'Bewoners melden hiermee losliggende stoeptegels.',
	},
	en: {
		shortDescription: 'Reports about public space',
		longDescription: 'Residents use it to report loose paving stones.',
	},
}

let slug: string
let component: SeededObject

test.beforeAll(async () => {
	await fx.init()
	test.skip(SCHEMA_PUBLICCODE === null, 'this instance has no publiccode schema')
	slug = `${fx.prefix}-componenten`
	await fx.createCatalog('Componenten', {
		slug,
		registers: [REG_PUBLICATION],
		schemas: [SCHEMA_PUBLICCODE],
	})
	component = await fx.createPubliccode('Signalen', {
		softwareType: 'standalone/web',
		description: DESCRIPTION,
	})
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('objects of another schema in a catalog', () => {
	test('the Componenten catalog lists name, summary and updated', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		await bootApp(page)
		await navToRoute(page, `/publications/${slug}`)
		await waitIndexBody(page)

		const headers = page.locator('[data-testid="cn-object-list-table"] th')
		await expect(headers.filter({ hasText: /^\s*(Name|Naam)\s*$/ })).toHaveCount(
			1,
		)
		await expect(
			headers.filter({ hasText: /^\s*(Summary|Samenvatting)\s*$/ }),
		).toHaveCount(1)
		await expect(
			headers.filter({ hasText: /^\s*(Updated|Bijgewerkt)\s*$/ }),
		).toHaveCount(1)
		await expect(headers.filter({ hasText: /Software ?type/i })).toHaveCount(0)

		await expect(rowByTitle(page, component.title)).toContainText(
			DESCRIPTION.nl.shortDescription,
		)
		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test('the sidebar with filters and columns is offered', async ({ page }) => {
		const errors = trackPageErrors(page)
		await bootApp(page)
		await navToRoute(page, `/publications/${slug}`)
		await waitIndexBody(page)

		// CnActionsBar renders the labelled Filter button only in its board
		// layout; on a table page the same toggle is the icon-only "Search and
		// columns" button, which carries no test id.
		const toggle = page
			.locator('[data-testid="cn-actions-bar-filter-button"]')
			.or(
				page.getByRole('button', {
					name: /Search and columns|Zoeken en kolommen/,
				}),
			)
			.first()
		await expect(toggle).toBeVisible({ timeout: 15000 })
		// CnIndexSidebar has no root class of its own: its root is NcAppSidebar.
		const sidebar = page.locator('.app-sidebar').first()
		if (!(await sidebar.isVisible().catch(() => false))) {
			await toggle.click()
		}
		await expect(sidebar).toBeVisible({ timeout: 10000 })

		// The Columns tab offers the publiccode properties the default set leaves out.
		await sidebar
			.getByRole('tab', { name: /Columns|Kolommen/ })
			.first()
			.click()
		await expect(
			sidebar
				.locator('.cn-sidebar-columns__group-content')
				.getByText(/Software ?type/i)
				.first(),
		).toBeVisible({ timeout: 10000 })
		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test('the component is edited in the form, with its description', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		await bootApp(page)
		await navToRoute(
			page,
			`/publications/${slug}/${REG_PUBLICATION}/${SCHEMA_PUBLICCODE}/${component.id}`,
		)
		const edit = page.locator('[data-testid="cn-detail-page-edit"]').first()
		await expect(edit).toBeVisible({ timeout: 20000 })
		await edit.click()

		const json = page
			.locator('[role="dialog"] .cn-form-dialog__json-wrapper')
			.first()
		await expect(json).toBeVisible({ timeout: 10000 })
		await expect(json).toContainText(DESCRIPTION.nl.shortDescription)
		await expect(json).toContainText(DESCRIPTION.en.longDescription)
		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test('a component opens on its detail page with its description per language', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		await bootApp(page)
		await navToRoute(page, `/publications/${slug}`)
		await waitIndexBody(page)

		await rowByTitle(page, component.title).click()
		await expect(page).toHaveURL(
			new RegExp(
				`/publications/${slug}/${REG_PUBLICATION}/${SCHEMA_PUBLICCODE}/${component.id}$`,
			),
		)
		const detail = page.locator('[data-testid="cn-detail-page"]').first()
		await expect(detail).toBeVisible({ timeout: 20000 })
		// The Data card shows its first fields only when it has more; the
		// description can sit behind "Show all N fields".
		await expect(
			detail.locator('.cn-object-data-widget__grid-wrap').first(),
		).toBeVisible({ timeout: 20000 })
		const showAll = detail.locator('.cn-object-data-widget__toggle').first()
		if (
			(await showAll.isVisible().catch(() => false))
			&& (await showAll.getAttribute('aria-expanded')) !== 'true'
		) {
			await showAll.click()
		}
		for (const language of Object.values(DESCRIPTION)) {
			await expect(detail).toContainText(language.shortDescription)
			await expect(detail).toContainText(language.longDescription)
		}
		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test('a ctrl click opens the component in a new tab and keeps the list', async ({
		page,
		context,
	}) => {
		const errors = trackPageErrors(page)
		await bootApp(page)
		await navToRoute(page, `/publications/${slug}`)
		await waitIndexBody(page)
		const listUrl = page.url()

		const [tab] = await Promise.all([
			context.waitForEvent('page'),
			rowByTitle(page, component.title).click({
				modifiers: ['ControlOrMeta'],
			}),
		])
		await tab.waitForLoadState('domcontentloaded')
		expect(tab.url()).toContain(
			`/publications/${slug}/${REG_PUBLICATION}/${SCHEMA_PUBLICCODE}/${component.id}`,
		)
		expect(page.url()).toBe(listUrl)
		await tab.close()
		expect(fatalErrors(errors)).toHaveLength(0)
	})
})
