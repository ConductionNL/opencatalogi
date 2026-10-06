/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The navigation lists every catalog the user can access, each under its own
 * title, directly after Dashboard and before Search. An entry opens that
 * catalog's publications (`/publications/<slug>`) and is the active entry
 * there; creating, renaming or deleting a catalog updates the list without a
 * page reload.
 *
 * Each entry is the CnAppNav item `[data-testid="cn-nav-entry-catalog-<slug>"]`,
 * and the active one carries `aria-current="page"` on its link.
 *
 * The listed catalogs are seeded through the OpenRegister object API, the
 * created one goes through the Catalogs page's Add Catalog dialog; all carry a
 * run-unique slug and afterAll removes everything this run created.
 */
import type { Page } from '@playwright/test'
import type { SeededObject } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	bootApp,
	fatalErrors,
	navTo,
	trackPageErrors,
} from '../spec-coverage/_nav.ts'
import { dialog, fillField, rowAction, rowByTitle, waitIndexBody } from './_crud.ts'
import {
	ADMIN_USER,
	Fixtures,
	REG_PUBLICATION,
	SCHEMA_CATALOG,
	SCHEMA_PUBLICATION,
} from './_fixtures.ts'

interface SeededCatalog extends SeededObject {
	slug: string
}

const fx = new Fixtures()

let catalogA: SeededCatalog
let catalogB: SeededCatalog
// Titled exactly like the built-in Search entry: its label must render as typed.
let catalogSearch: SeededCatalog

/**
 * The navigation entry for one catalog.
 *
 * @param page The page.
 * @param slug The catalog slug.
 */
function catalogEntry(page: Page, slug: string) {
	return page.locator(`[data-testid="cn-nav-entry-catalog-${slug}"]`).first()
}

/**
 * The link inside a navigation entry, which carries the active state.
 *
 * @param page The page.
 * @param slug The catalog slug.
 */
function catalogLink(page: Page, slug: string) {
	return catalogEntry(page, slug).locator('a.app-navigation-entry-link').first()
}

/**
 * A run-unique catalog slug.
 *
 * @param name Title suffix, turned into the slug suffix.
 */
function slugFor(name: string): string {
	return `${fx.prefix}-${name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
}

/**
 * Seed a catalog with a run-unique slug.
 *
 * @param name Title suffix, also turned into the slug suffix.
 * @param extra Extra fields, which override the defaults (a `title` replaces the prefixed one).
 */
async function seedCatalog(
	name: string,
	extra: Record<string, unknown> = {},
): Promise<SeededCatalog> {
	const slug = slugFor(name)
	const seeded = await fx.createCatalog(name, {
		slug,
		status: 'development',
		...extra,
	})
	return { ...seeded, slug }
}

/**
 * The title of an OpenRegister register or schema, as the catalog dialog's
 * pickers label it.
 *
 * @param kind `registers` or `schemas`.
 * @param id The register or schema id.
 */
async function titleOf(
	kind: 'registers' | 'schemas',
	id: number | string,
): Promise<string> {
	const res = await fx.api.get(`/index.php/apps/openregister/api/${kind}/${id}`)
	expect(res.ok(), `${kind} ${id} readable`).toBe(true)
	return String((await res.json()).title)
}

/** The test user's Nextcloud language. */
async function userLanguage(): Promise<string> {
	const res = await fx.api.get(`/ocs/v2.php/cloud/users/${ADMIN_USER}?format=json`)
	expect(res.ok(), 'user details readable').toBe(true)
	return String((await res.json()).ocs.data.language)
}

/**
 * Set the test user's Nextcloud language.
 *
 * @param language The language code.
 */
async function setUserLanguage(language: string): Promise<void> {
	const res = await fx.api.put(
		`/ocs/v2.php/cloud/users/${ADMIN_USER}?format=json`,
		{
			data: { key: 'language', value: language },
		},
	)
	expect(res.ok(), `language set to ${language}`).toBe(true)
}

/**
 * Pick one option in an NcSelect: type into its search input, then click the
 * option whose whole text is `option`. The dropdown renders outside the dialog.
 *
 * @param page The page.
 * @param scope The dialog holding the select.
 * @param label The select's input label.
 * @param option The option text.
 */
async function pickOption(
	page: Page,
	scope: ReturnType<Page['locator']>,
	label: string,
	option: string,
): Promise<void> {
	const input = scope.getByLabel(label, { exact: true }).first()
	await input.click()
	await input.fill(option)
	const escaped = option.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	await page
		.locator('[role="option"]')
		.filter({ hasText: new RegExp(`^\\s*${escaped}\\s*$`) })
		.first()
		.click()
	await input.press('Escape')
}

/**
 * The id of the catalog with this slug.
 *
 * @param slug The catalog slug.
 */
async function catalogIdBySlug(slug: string): Promise<string> {
	const res = await fx.api.get(
		`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_CATALOG}`
			+ `?slug=${encodeURIComponent(slug)}&_source=database`,
	)
	expect(res.ok(), 'catalog lookup succeeds').toBe(true)
	const match = ((await res.json()).results ?? []).find(
		(row: Record<string, unknown>) => row.slug === slug,
	)
	expect(match, `catalog ${slug} exists`).toBeTruthy()
	return String(match.id ?? match['@self']?.id)
}

/**
 * Every distinct catalog slug the admin can read, walking all pages of the
 * same listing the navigation reads.
 */
async function listedSlugs(): Promise<string[]> {
	const slugs = new Set<string>()
	for (let page = 1; page <= 50; page++) {
		const res = await fx.api.get(
			`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_CATALOG}`
				+ `?_limit=100&_page=${page}&_source=database`,
		)
		expect(res.ok(), 'catalog listing succeeds').toBe(true)
		const body = await res.json()
		const results: Array<Record<string, unknown>> = body.results ?? []
		for (const row of results) {
			if (typeof row.slug === 'string' && row.slug !== '') slugs.add(row.slug)
		}
		if (results.length === 0 || page >= (body.pages ?? 1)) break
	}
	return [...slugs]
}

/**
 * The manifest ids of the main-list navigation entries, in rendered order.
 *
 * @param page The page.
 */
async function navEntryIds(page: Page): Promise<string[]> {
	return page
		.locator('[data-testid="cn-nav"] [data-testid^="cn-nav-entry-"]')
		.evaluateAll((elements) =>
			elements.map((element) =>
				(element.getAttribute('data-testid') ?? '').replace(
					/^cn-nav-entry-/,
					'',
				),
			),
		)
}

test.beforeAll(async () => {
	await fx.init()
	catalogA = await seedCatalog('Sidebar A')
	catalogB = await seedCatalog('Sidebar B')
	catalogSearch = await seedCatalog('Search titled', { title: 'Search' })
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('catalog entries in the navigation', () => {
	test('one entry per accessible catalog, under its title, between Dashboard and Search', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		const slugA = catalogA.slug
		const slugB = catalogB.slug

		await bootApp(page)

		await expect(catalogEntry(page, slugA)).toBeVisible({ timeout: 15000 })
		await expect(catalogEntry(page, slugA)).toContainText(catalogA.title)
		await expect(catalogEntry(page, slugB)).toContainText(catalogB.title)

		const ids = await navEntryIds(page)
		const dashboard = ids.indexOf('Dashboard')
		const search = ids.indexOf('Search')
		expect(dashboard, 'Dashboard entry rendered').toBeGreaterThanOrEqual(0)
		expect(search, 'Search entry rendered').toBeGreaterThan(dashboard)

		// Everything between Dashboard and Search is a catalog entry, and there
		// is exactly one per catalog the admin can read.
		const between = ids.slice(dashboard + 1, search)
		expect(between.every((id) => id.startsWith('catalog-'))).toBe(true)
		expect(between).toContain(`catalog-${slugA}`)
		expect(between).toContain(`catalog-${slugB}`)
		expect(between.sort()).toEqual(
			(await listedSlugs()).map((slug) => `catalog-${slug}`).sort(),
		)

		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test("an entry opens its catalog's publications and is the active entry there", async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		const slugA = catalogA.slug
		const slugB = catalogB.slug

		await bootApp(page)
		await catalogEntry(page, slugA).click()

		await expect(page).toHaveURL(new RegExp(`/publications/${slugA}$`))
		await expect(catalogLink(page, slugA)).toHaveAttribute(
			'aria-current',
			'page',
		)
		await expect(catalogLink(page, slugB)).not.toHaveAttribute(
			'aria-current',
			'page',
		)
		await expect(
			page
				.locator(
					'[data-testid="cn-nav-entry-Dashboard"] a.app-navigation-entry-link',
				)
				.first(),
		).not.toHaveAttribute('aria-current', 'page')

		const index = page.locator('[data-testid="cn-index-page"]').first()
		await expect(index).toBeVisible({ timeout: 15000 })
		await expect(
			index.locator('[data-testid="cn-cta-primary"]').first(),
		).toHaveAccessibleName('Add Publication')

		// Switching catalogs moves the active state with it.
		await catalogEntry(page, slugB).click()
		await expect(page).toHaveURL(new RegExp(`/publications/${slugB}$`))
		await expect(catalogLink(page, slugB)).toHaveAttribute(
			'aria-current',
			'page',
		)
		await expect(catalogLink(page, slugA)).not.toHaveAttribute(
			'aria-current',
			'page',
		)

		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test('a catalog titled like a built-in entry keeps its own title and route', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		const slug = catalogSearch.slug
		expect(catalogSearch.title).toBe('Search')

		// In English "Search" translates to itself, so run in Dutch, where a
		// translated catalog title would read differently.
		const previousLanguage = await userLanguage()
		await setUserLanguage('nl')
		try {
			await bootApp(page)

			const entry = catalogEntry(page, slug)
			await expect(entry).toBeVisible({ timeout: 15000 })

			// The built-in Search entry is still there, separately, and translated.
			const builtInSearch = page.locator('[data-testid="cn-nav-entry-Search"]')
			await expect(builtInSearch).toHaveCount(1)
			await expect(
				builtInSearch.locator('.app-navigation-entry__name').first(),
			).not.toHaveText('Search')
			await expect(
				entry.locator('.app-navigation-entry__name').first(),
			).toHaveText('Search')
			const ids = await navEntryIds(page)
			expect(ids.indexOf(`catalog-${slug}`)).toBeLessThan(
				ids.indexOf('Search'),
			)

			await entry.click()
			await expect(page).toHaveURL(new RegExp(`/publications/${slug}$`))
			await expect(catalogLink(page, slug)).toHaveAttribute(
				'aria-current',
				'page',
			)
			await expect(
				builtInSearch.locator('a.app-navigation-entry-link').first(),
			).not.toHaveAttribute('aria-current', 'page')
		} finally {
			await setUserLanguage(previousLanguage)
		}

		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test("a publication detail page keeps its catalog's entry active", async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		const slugA = catalogA.slug
		const slugB = catalogB.slug
		const publication = await fx.createPublication('Sidebar publication')

		await bootApp(page)
		await catalogEntry(page, slugA).click()
		await expect(page).toHaveURL(new RegExp(`/publications/${slugA}$`))
		await waitIndexBody(page)
		await rowByTitle(page, publication.title).click()

		await expect(page).toHaveURL(
			new RegExp(`/publications/${slugA}/${publication.id}(\\?|$)`),
		)
		await expect(catalogLink(page, slugA)).toHaveAttribute(
			'aria-current',
			'page',
		)
		await expect(catalogLink(page, slugB)).not.toHaveAttribute(
			'aria-current',
			'page',
		)

		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test('creating, renaming and deleting a catalog updates the navigation without a reload', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)

		const title = fx.label('Sidebar C')
		const slugC = slugFor('Sidebar C')
		const registerTitle = await titleOf('registers', REG_PUBLICATION)
		const schemaTitle = await titleOf('schemas', SCHEMA_PUBLICATION)

		await bootApp(page)
		// A full page load would drop this marker.
		await page.evaluate(() => {
			;(
				window as unknown as { __catalogSidebarSpec: boolean }
			).__catalogSidebarSpec = true
		})

		// Create through the Catalogs page's Add Catalog dialog.
		await navTo(page, 'CatalogsMenu')
		await waitIndexBody(page)
		await page
			.locator('[data-testid="cn-index-page"]')
			.getByRole('button', { name: /add catalog/i })
			.first()
			.click()
		const createDialog = dialog(page)
		await expect(createDialog).toBeVisible({ timeout: 10000 })
		await fillField(createDialog, 'Title', title)
		await fillField(createDialog, 'Slug', slugC)
		await pickOption(page, createDialog, 'Registers*', registerTitle)
		await pickOption(
			page,
			createDialog,
			'Schemas*',
			`${schemaTitle} (${registerTitle})`,
		)
		await expect(catalogEntry(page, slugC)).toHaveCount(0)
		const add = createDialog.getByRole('button', { name: /^add$/i }).first()
		await expect(add).toBeEnabled()
		await add.click()
		await expect(catalogEntry(page, slugC)).toContainText(title, {
			timeout: 15000,
		})
		await expect(createDialog).toBeHidden({ timeout: 15000 })
		const created = { id: await catalogIdBySlug(slugC), title }

		// The Catalogs table keeps the list it loaded on mount, so reopen the page
		// in the app to get a row for the new catalog.
		await navTo(page, 'Dashboard')
		await navTo(page, 'CatalogsMenu')
		await waitIndexBody(page)

		// Rename through the edit dialog.
		const renamed = `${created.title} renamed`
		await rowAction(page, created.title, 'edit')
		const editDialog = dialog(page)
		await expect(editDialog).toBeVisible({ timeout: 10000 })
		await fillField(editDialog, 'Title', renamed)
		await editDialog
			.getByRole('button', { name: /^save$/i })
			.first()
			.click()
		await expect(catalogEntry(page, slugC)).toContainText(renamed, {
			timeout: 15000,
		})

		// Delete through the delete dialog. The original title is a prefix of the
		// new one, so the row matches whether or not the table shows the rename.
		// Opening the dialog loads the catalog's related data; confirming before
		// those requests settle makes them 404 against the deleted catalog.
		await expect(editDialog).toBeHidden({ timeout: 15000 })
		const relatedLoaded = Promise.all(
			['audit-trails', 'uses', 'used', 'files'].map((related) =>
				page.waitForResponse((response) =>
					new URL(response.url()).pathname.endsWith(
						`/${created.id}/${related}`,
					),
				),
			),
		)
		await rowAction(page, created.title, 'delete')
		const deleteDialog = dialog(page)
		await expect(deleteDialog).toBeVisible({ timeout: 10000 })
		await relatedLoaded
		await deleteDialog
			.getByRole('button', { name: /^delete$/i })
			.first()
			.click()
		await expect(catalogEntry(page, slugC)).toHaveCount(0, { timeout: 15000 })

		expect(
			await page.evaluate(
				() =>
					(window as unknown as { __catalogSidebarSpec?: boolean })
						.__catalogSidebarSpec,
			),
			'no page reload happened',
		).toBe(true)
		expect(fatalErrors(errors)).toHaveLength(0)
	})
})
