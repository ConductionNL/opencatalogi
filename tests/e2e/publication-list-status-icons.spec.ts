/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * In the table view of the Publications page, a publication row starts with
 * the status icon its card shows, colored by that status, as the list did
 * before it moved to CnIndexPage.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The icon at the start of each row and its
 * color, compared with the color the theme variable resolves to. The status is
 * the server's state, so the draft has a past publication date: by its dates
 * alone it would look published.
 *
 * @e2e openspec/changes/publication-list-status-icons/specs/publications/spec.md#scenario-a-public-publication-starts-with-a-green-list-icon
 * @e2e openspec/changes/publication-list-status-icons/specs/publications/spec.md#scenario-a-draft-starts-with-an-orange-pencil-whatever-its-dates
 * @e2e openspec/changes/publication-list-status-icons/specs/publications/spec.md#scenario-a-withdrawn-publication-starts-with-a-red-alert
 */
import type { Page } from '@playwright/test'
import type { SeededObject } from './workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import { bootApp, navToRoute } from './spec-coverage/_nav.ts'
import { rowByTitle, waitIndexBody } from './workflows/_crud.ts'
import {
	Fixtures,
	REG_PUBLICATION,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

const fx = new Fixtures()

let slug: string
let published: SeededObject
let draft: SeededObject
let withdrawn: SeededObject

/**
 * Open the catalog's publications page in the table view.
 *
 * @param page The page.
 */
async function openTable(page: Page): Promise<void> {
	await bootApp(page)
	await navToRoute(page, `/publications/${encodeURIComponent(slug)}`)
	await waitIndexBody(page)
	await page
		.getByRole('button', { name: /^(Table|Tabel)$/ })
		.first()
		.click()
	await expect(page.locator('[data-testid="cn-object-row"]').first()).toBeVisible({
		timeout: 20000,
	})
}

/**
 * The icon a row starts with: its name and whether it has the color a theme
 * variable resolves to.
 *
 * @param page The page.
 * @param title The publication's title.
 * @param variable The theme color variable.
 */
async function rowIcon(
	page: Page,
	title: string,
	variable: string,
): Promise<{ icon: string; colored: boolean }> {
	const icon = rowByTitle(page, title)
		.locator('td.cn-table-col--icon .material-design-icon')
		.first()
	await expect(icon).toBeVisible()
	return icon.evaluate((element, name) => {
		const probe = document.createElement('span')
		probe.style.color = `var(${name})`
		document.body.appendChild(probe)
		const expected = getComputedStyle(probe).color
		probe.remove()
		return {
			icon:
				[...element.classList].find(
					(cls) => cls.endsWith('-icon') && cls !== 'material-design-icon',
				) ?? '',
			colored: getComputedStyle(element).color === expected,
		}
	}, variable)
}

test.beforeAll(async () => {
	await fx.init()
	slug = `${fx.prefix}-status-icons`
	await fx.createCatalog('Status icons', {
		slug,
		registers: [REG_PUBLICATION],
		schemas: [SCHEMA_PUBLICATION],
	})
	published = await fx.createPublication('Published council decision', {
		status: 'published',
		publicationDate: '2020-01-01T00:00:00+00:00',
	})
	draft = await fx.createPublication('Draft council decision', {
		publicationDate: '2020-01-01T00:00:00+00:00',
	})
	withdrawn = await fx.createPublication('Withdrawn council decision', {
		status: 'published',
		publicationDate: '2020-01-01T00:00:00+00:00',
		depublicationDate: '2021-01-01T00:00:00+00:00',
	})
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('the status icon a publication row starts with', () => {
	test('a public publication starts with a green list icon', async ({ page }) => {
		await openTable(page)
		expect(
			await rowIcon(page, published.title, '--color-element-success'),
		).toEqual({
			icon: 'list-box-outline-icon',
			colored: true,
		})
	})

	test('a draft with a past publication date starts with an orange pencil', async ({
		page,
	}) => {
		await openTable(page)
		expect(await rowIcon(page, draft.title, '--color-element-warning')).toEqual({
			icon: 'pencil-icon',
			colored: true,
		})
	})

	test('a withdrawn publication starts with a red alert', async ({ page }) => {
		await openTable(page)
		expect(
			await rowIcon(page, withdrawn.title, '--color-element-error'),
		).toEqual({
			icon: 'alert-outline-icon',
			colored: true,
		})
	})
})
