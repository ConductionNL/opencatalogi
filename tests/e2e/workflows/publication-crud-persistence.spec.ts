/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DEEP, data-dependent CRUD-with-persistence coverage for PUBLICATIONS.
 *
 * Publications are OpenRegister objects in register 14 (publication) / schema
 * 53 (publication). The Publications index page (manifest route
 * /publications/:catalogSlug, config register:"publication" schema:"publication")
 * is reached by clicking a catalog row on the Catalogs page.
 *
 * TWO LAYERS:
 *
 *  1. Backend CRUD persistence (GREEN) — create → read → update → delete via
 *     the OpenRegister object REST API (the store API the frontend uses),
 *     asserting persistence on each step. The publication schema's real
 *     fields (title, summary, description) are written and read back.
 *
 *  2. UI list reflection (test.fixme — BLOCKED) — the Publications index is
 *     only reachable through a catalog row, and the Catalogs index is broken
 *     by the unresolved `@resolve:catalog_register` placeholder (see
 *     catalog-crud-persistence.spec.ts), so there is no headless UI journey
 *     into the Publications list. Quarantined until the catalog index renders.
 *
 * Cleanup: afterAll removes everything this run created.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test workflows/publication-crud
 */
import { test, expect } from '@playwright/test'
import { Fixtures, REG_PUBLICATION, SCHEMA_PUBLICATION } from './_fixtures'

const fx = new Fixtures()

test.beforeAll(async () => {
	await fx.init()
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('publication CRUD persistence', () => {
	test(
		// @e2e publications::publication-full-crud-persists-in-openregister
		'Publication — create → read → update → delete persists in OpenRegister (store API)',
		async () => {
			const title = fx.label('Publication CRUD')

			// ---- CREATE -------------------------------------------------
			const created = await fx.createPublication('Publication CRUD', {
				description: 'initial description',
			})
			expect(created.id).toBeTruthy()
			expect(created.title).toBe(title)

			// ---- READ (persisted) ---------------------------------------
			let fetched = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, created.id)
			expect(fetched, 'publication readable after create').toBeTruthy()
			expect(fetched!.title).toBe(title)
			expect(fetched!.description).toBe('initial description')

			const inList = (await fx.list(REG_PUBLICATION, SCHEMA_PUBLICATION, 500))
				.some((p) => (p.id as string) === created.id)
			expect(inList, 'publication appears in the publication collection').toBe(true)

			// ---- UPDATE (persisted) -------------------------------------
			const newTitle = `${title} EDITED`
			const putRes = await fx.api.put(
				`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${created.id}`,
				{ data: { title: newTitle, summary: 'edited summary', description: 'edited description' } },
			)
			expect(putRes.ok(), 'update request succeeds').toBe(true)
			fetched = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, created.id)
			expect(fetched!.title, 'edited title persisted').toBe(newTitle)
			expect(fetched!.summary, 'edited summary persisted').toBe('edited summary')
			expect(fetched!.description, 'edited description persisted').toBe('edited description')

			// ---- DELETE (persisted) -------------------------------------
			await fx.remove(REG_PUBLICATION, SCHEMA_PUBLICATION, created.id)
			const gone = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, created.id)
			expect(gone, 'publication deleted from OpenRegister').toBeFalsy()
		},
	)

	// BLOCKED — the Publications list is only reachable by clicking a catalog
	// row, and the Catalogs index is broken by the unresolved @resolve
	// placeholder (see catalog-crud-persistence.spec.ts). No headless UI route
	// reaches the Publications index, so its row rendering cannot be driven.
	test.fixme(
		// @e2e publications::publication-row-renders-in-index-ui
		'Publication — a created publication renders as a row in the Publications index UI (BLOCKED: catalog index @resolve bug hides the entry path)',
		async () => {
			// Intentionally empty: see fixme reason above.
		},
	)
})
