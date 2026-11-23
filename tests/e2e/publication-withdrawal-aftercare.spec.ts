/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What a withdrawn publication still says to the public (REQ-PWA-002).
 *
 * WHAT THIS SPEC IS LOOKING FOR. An editor withdraws a public publication with
 * an internal reason, a public reason and Show the title ticked. The stored
 * depublication carries both reasons and the choice, and the publication's
 * public link answers 410 with its id, the moment, the public reason and the
 * title, and nothing else of the record. Withdrawn without the title, the
 * tombstone carries no title.
 *
 * The base URL comes from NEXTCLOUD_URL (base-url.ts); anonymous calls run in a
 * context with an empty storage state.
 *
 * @e2e openspec/changes/publication-withdrawal-aftercare/specs/publications/spec.md#scenario-the-officer-chooses-what-the-tombstone-says REQ-PWA-002
 */
import { expect, test } from './authenticated-request.ts'
import { publicationCatalog } from './publication-catalog.ts'
import { BASE, Fixtures, REG_PUBLICATION } from './workflows/_fixtures.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

test.describe('Publication withdrawal aftercare', () => {
	const fx = new Fixtures()

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('the officer chooses what the tombstone says', async ({
		request: admin,
		playwright,
	}) => {
		const catalog = await publicationCatalog(admin, 'e2e-tombstone')
		const shown = await fx.createPublication(
			'tombstone-shown',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_PUBLICATION,
		)
		const hidden = await fx.createPublication(
			'tombstone-hidden',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_PUBLICATION,
		)

		const withShown = await admin.post(
			`${API_BASE}/publications/${shown.id}/withdraw`,
			{
				data: {
					reason: 'AVG-melding 12',
					publicReason: 'Gepubliceerd in strijd met de AVG',
					tombstoneShowsTitle: true,
				},
			},
		)
		expect(withShown.ok()).toBe(true)
		const stored = (await withShown.json()).depublication
		expect(stored.reason).toBe('AVG-melding 12')
		expect(stored.publicReason).toBe('Gepubliceerd in strijd met de AVG')
		expect(stored.tombstoneShowsTitle).toBe(true)

		const withHidden = await admin.post(
			`${API_BASE}/publications/${hidden.id}/withdraw`,
			{
				data: {
					reason: 'AVG-melding 13',
					publicReason: 'Gepubliceerd in strijd met de AVG',
				},
			},
		)
		expect(withHidden.ok()).toBe(true)

		const anonymous = await playwright.request.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
		})
		try {
			const tombstone = await anonymous.get(
				`${API_BASE}/${encodeURIComponent(catalog.slug)}/${shown.id}`,
			)
			expect(tombstone.status()).toBe(410)
			const body = await tombstone.json()
			expect(body.id).toBe(shown.id)
			expect(body.publicReason).toBe('Gepubliceerd in strijd met de AVG')
			expect(typeof body.title).toBe('string')
			expect(String(body.withdrawnAt ?? '')).not.toBe('')
			expect(Object.keys(body).sort()).toEqual([
				'id',
				'publicReason',
				'title',
				'withdrawnAt',
			])

			const untitled = await anonymous.get(
				`${API_BASE}/${encodeURIComponent(catalog.slug)}/${hidden.id}`,
			)
			expect(untitled.status()).toBe(410)
			expect(await untitled.json()).not.toHaveProperty('title')
		} finally {
			await anonymous.dispose()
		}
	})
})
