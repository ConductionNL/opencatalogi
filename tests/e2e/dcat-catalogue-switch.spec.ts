/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The DCAT feed switches on per catalogue (DCAT-010, dcat-catalogue-completion
 * task 6.2).
 *
 * WHAT THIS SPEC IS LOOKING FOR. A catalogue without the DCAT switch has no
 * feed: `GET /api/catalogs/{slug}/dcat` answers 404 and the instance document
 * `GET /api/dcat` does not list it. Once the catalogue carries `hasDcat: true`
 * (what the "Has DCAT feed" switch in the catalogue form saves; the form's
 * payload is pinned by `src/modals/catalog/CatalogModalForm.spec.js`), the
 * feed answers and the instance document lists the catalogue's endpoint.
 *
 * Written for the nightly; it needs a live instance with the publication
 * register imported.
 *
 * @e2e openspec/specs/dcat-ap-harvest/spec.md#scenario-enabling-dcat-for-a-catalog DCAT-010
 */
import { expect, test } from '@playwright/test'
import { Fixtures, REG_PUBLICATION, SCHEMA_CATALOG } from './workflows/_fixtures.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

const fx = new Fixtures()

test.beforeAll(async () => {
	await fx.init()
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

/**
 * The catalogue endpoints the instance document lists.
 *
 * @param document The `GET /api/dcat` JSON-LD answer.
 * @return The `@id` of each listed catalogue.
 */
function listedCatalogs(document: Record<string, unknown>): string[] {
	const graph = (document['@graph'] ?? []) as Array<Record<string, unknown>>
	return graph
		.filter((node) => node['@type'] === 'dcat:Catalog')
		.map((node) => String(node['@id'] ?? ''))
}

test.describe('DCAT feed per catalogue', () => {
	test('an administrator switches the DCAT feed on for a catalogue', async () => {
		const created = await fx.createCatalog('DCAT switch', {
			hasDcat: false,
			slug: `${fx.prefix}-dcat-switch`.toLowerCase().replace(/[^a-z0-9-]/g, '-'),
		})
		const stored = await fx.fetch(REG_PUBLICATION, SCHEMA_CATALOG, created.id)
		expect(stored, 'catalogue readable after create').toBeTruthy()
		const slug = String(stored!.slug ?? '')
		expect(slug, 'the catalogue has a slug').not.toBe('')
		const feed = `${API_BASE}/catalogs/${slug}/dcat`

		const off = await fx.api.get(feed, { headers: { Accept: 'application/ld+json' } })
		expect(off.status(), 'no feed while the switch is off').toBe(404)
		const before = await fx.api.get(`${API_BASE}/dcat`, { headers: { Accept: 'application/ld+json' } })
		expect(before.ok()).toBe(true)
		expect(listedCatalogs(await before.json()).some((id) => id.endsWith(`/catalogs/${slug}/dcat`))).toBe(false)

		const switched = await fx.api.put(
			`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/${SCHEMA_CATALOG}/${created.id}`,
			{ data: { ...stored, hasDcat: true } },
		)
		expect(switched.ok(), `switch on: ${await switched.text()}`).toBe(true)
		const after = await fx.fetch(REG_PUBLICATION, SCHEMA_CATALOG, created.id)
		expect(after!.hasDcat, 'the stored catalogue keeps hasDcat').toBe(true)

		const on = await fx.api.get(feed, { headers: { Accept: 'application/ld+json' } })
		expect(on.ok(), `feed: ${await on.text()}`).toBe(true)
		const instance = await fx.api.get(`${API_BASE}/dcat`, { headers: { Accept: 'application/ld+json' } })
		expect(instance.ok()).toBe(true)
		expect(listedCatalogs(await instance.json()).some((id) => id.endsWith(`/catalogs/${slug}/dcat`))).toBe(true)
	})
})
