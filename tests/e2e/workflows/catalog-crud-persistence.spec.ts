/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DEEP, data-dependent CRUD-with-persistence coverage for CATALOGS.
 *
 * Catalogs are OpenRegister objects in register 14 (publication) / schema 54
 * (catalog). The "Catalogs" CnAppNav entry (manifest menu id CatalogsMenu,
 * in the settings foldout) mounts a CnIndexPage whose manifest config binds
 * its data source to `@resolve:catalog_register` / `@resolve:catalog_schema`.
 *
 * TWO LAYERS:
 *
 *  1. Backend CRUD persistence (GREEN) — drives create → read → update →
 *     delete through the OpenRegister object REST API (the real store API the
 *     frontend uses) and asserts every mutation persists. This proves the
 *     catalog data model and the store endpoints genuinely work end-to-end.
 *
 *  2. UI list reflection (test.fixme — REAL BUG) — the Catalogs CnIndexPage
 *     does NOT substitute the `@resolve:catalog_register` /
 *     `@resolve:catalog_schema` manifest placeholders before its list fetch.
 *     Observed network call:
 *         GET /apps/openregister/api/objects/@resolve:catalog_register/
 *             @resolve:catalog_schema   → 404 "Register not found:
 *                                          '@resolve:catalog_register'"
 *     so the index always renders "No items found" even though catalogs exist
 *     at 14/54. This makes UI-driven create/edit/delete (and clicking into a
 *     catalog detail) impossible. The create dialog also renders with an empty
 *     form body (no schema fields). These legs are quarantined as test.fixme
 *     until the placeholder resolution is fixed in the manifest renderer.
 *
 * Cleanup: afterAll removes everything this run created (tracked + prefix
 * sweep).
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test workflows/catalog-crud
 */
import { test, expect } from '@playwright/test'
import { bootApp, navTo, trackPageErrors, fatalErrors } from '../spec-coverage/_nav'
import { Fixtures, REG_PUBLICATION, SCHEMA_CATALOG, SCHEMA_PUBLICATION } from './_fixtures'
import { waitIndexBody } from './_crud'

const fx = new Fixtures()

test.beforeAll(async () => {
	await fx.init()
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('catalog CRUD persistence', () => {
	test(
		// @e2e catalogs::catalog-full-crud-persists-in-openregister
		'Catalog — create → read → update → delete persists in OpenRegister (store API)',
		async () => {
			const title = fx.label('Catalog CRUD')

			// ---- CREATE -------------------------------------------------
			const created = await fx.createCatalog('Catalog CRUD')
			expect(created.id).toBeTruthy()
			expect(created.title).toBe(title)

			// ---- READ (persisted) ---------------------------------------
			let fetched = await fx.fetch(REG_PUBLICATION, SCHEMA_CATALOG, created.id)
			expect(fetched, 'catalog readable after create').toBeTruthy()
			expect(fetched!.title).toBe(title)
			// The catalog is wired to the publication register + schema.
			expect(fetched!.registers).toEqual([REG_PUBLICATION])
			expect(fetched!.schemas).toEqual([SCHEMA_PUBLICATION])

			// And it shows up in the collection (not an empty list).
			const inList = (await fx.list(REG_PUBLICATION, SCHEMA_CATALOG, 500))
				.some((c) => (c.id as string) === created.id)
			expect(inList, 'catalog appears in the catalog collection').toBe(true)

			// ---- UPDATE (persisted) -------------------------------------
			const newTitle = `${title} EDITED`
			const putRes = await fx.api.put(
				`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_CATALOG}/${created.id}`,
				{ data: { title: newTitle, summary: 'edited summary' } },
			)
			expect(putRes.ok(), 'update request succeeds').toBe(true)
			fetched = await fx.fetch(REG_PUBLICATION, SCHEMA_CATALOG, created.id)
			expect(fetched!.title, 'edited title persisted').toBe(newTitle)
			expect(fetched!.summary, 'edited summary persisted').toBe('edited summary')

			// ---- DELETE (persisted) -------------------------------------
			await fx.remove(REG_PUBLICATION, SCHEMA_CATALOG, created.id)
			const gone = await fx.fetch(REG_PUBLICATION, SCHEMA_CATALOG, created.id)
			expect(gone, 'catalog deleted from OpenRegister').toBeFalsy()
		},
	)

	// REAL BUG — see file header. The Catalogs CnIndexPage never resolves the
	// @resolve:catalog_register / @resolve:catalog_schema placeholders, so a
	// catalog that genuinely exists at 14/54 never renders as a row and the
	// whole UI CRUD journey (create form / edit / delete / detail) is blocked.
	test.fixme(
		// @e2e catalogs::catalog-row-renders-in-index-ui
		'Catalog — a created catalog renders as a row in the Catalogs index UI (BLOCKED: @resolve placeholder not substituted)',
		async ({ page }) => {
			const errors = trackPageErrors(page)
			const cat = await fx.createCatalog('Catalog UI Row')

			await bootApp(page)
			await navTo(page, 'CatalogsMenu', true)
			await expect(page.locator('[data-testid="cn-index-page"]').first())
				.toBeVisible({ timeout: 15000 })
			await waitIndexBody(page)

			// This is the assertion that fails today: the row never appears
			// because the list fetch hits the unresolved @resolve endpoint.
			await expect(
				page.locator('[data-testid="cn-object-row"]').filter({ hasText: cat.title }).first(),
			).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)
})
