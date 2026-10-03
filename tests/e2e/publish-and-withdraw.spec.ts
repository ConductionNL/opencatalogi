/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Publish now, withdraw with a reason, publish again, and withdraw one document.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The server answers the state the page gates
 * its buttons on. A withdrawal takes the publication off the public API and
 * stores a depublication with the editor, the reason and a withdrawal per
 * channel. Publish again makes it public. One document comes down alone, and
 * the publication around it stays public.
 *
 * @e2e openspec/specs/publications/spec.md#scenario-a-publication-with-a-past-publication-date
 * @e2e openspec/specs/publications/spec.md#scenario-a-withdrawn-publication
 * @e2e openspec/specs/publications/spec.md#scenario-an-editor-withdraws-a-publication-published-by-mistake
 * @e2e openspec/specs/publications/spec.md#scenario-a-reader-without-update-rights
 * @e2e openspec/specs/publications/spec.md#scenario-an-editor-restores-a-publication-after-fixing-it
 * @e2e openspec/specs/publications/spec.md#scenario-an-annex-with-personal-data
 */
import { expect, test } from './authenticated-request.ts'
import {
	BASE,
	Fixtures,
	REG_OPENCATALOGI,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

test.describe('Publish and withdraw in one action', () => {
	const fx = new Fixtures()

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('an editor withdraws a publication and publishes it again', async ({
		request: admin,
	}) => {
		const publication = await fx.createPublication(
			'withdraw',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_OPENCATALOGI,
		)

		const before = await admin.get(
			`${API_BASE}/publications/${publication.id}/visibility`,
		)
		expect(before.ok()).toBe(true)
		expect((await before.json()).state).toBe('public')

		const withdrawn = await admin.post(
			`${API_BASE}/publications/${publication.id}/withdraw`,
			{
				data: { reason: 'Wrong annex attached' },
			},
		)
		expect(withdrawn.ok()).toBe(true)
		const body = await withdrawn.json()
		expect(body.state).toBe('withdrawn')
		expect(body.depublication.reason).toBe('Wrong annex attached')
		expect(body.depublication.depublishedBy).toBe('admin')
		// No integriq source is set on a test instance, so the Woo-index
		// withdrawal is recorded as outstanding and named.
		expect(body.outstandingChannels).toContain('national-woo-index')

		const after = await admin.get(
			`${API_BASE}/publications/${publication.id}/visibility`,
		)
		expect((await after.json()).state).toBe('withdrawn')

		const again = await admin.post(
			`${API_BASE}/publications/${publication.id}/withdraw`,
			{
				data: { reason: 'Twice' },
			},
		)
		expect(again.status()).toBe(409)

		const republished = await admin.post(
			`${API_BASE}/publications/${publication.id}/publish`,
		)
		expect(republished.ok()).toBe(true)
		const restored = await admin.get(
			`${API_BASE}/publications/${publication.id}/visibility`,
		)
		expect((await restored.json()).state).toBe('public')
	})

	test('a caller without a session cannot withdraw', async ({ playwright }) => {
		const publication = await fx.createPublication(
			'anonymous',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_OPENCATALOGI,
		)
		const anonymous = await playwright.request.newContext({
			baseURL: BASE,
		})
		const refused = await anonymous.post(
			`${API_BASE}/publications/${publication.id}/withdraw`,
			{
				data: { reason: 'Not mine' },
			},
		)
		expect([401, 403, 412]).toContain(refused.status())
		await anonymous.dispose()
	})

	test('one annex comes down and the publication stays public', async ({
		request: admin,
	}) => {
		const publication = await fx.createPublication(
			'annex',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_OPENCATALOGI,
		)
		const annex = await fx.attachFile(
			REG_OPENCATALOGI,
			SCHEMA_PUBLICATION,
			publication.id,
			'bijlage-adres.txt',
			'Huisadres',
		)
		const other = await fx.attachFile(
			REG_OPENCATALOGI,
			SCHEMA_PUBLICATION,
			publication.id,
			'bijlage-besluit.txt',
			'Besluit',
		)

		const withdrawn = await admin.post(
			`${API_BASE}/publications/${publication.id}/files/${annex}/withdraw`,
			{
				data: { reason: 'Contains a home address' },
			},
		)
		expect(withdrawn.ok()).toBe(true)
		const body = await withdrawn.json()
		expect(body.depublication.file).toBe(String(annex))
		expect(body.depublication.reason).toBe('Contains a home address')

		const files = await admin.get(
			`/index.php/apps/openregister/api/objects/${REG_OPENCATALOGI}/${SCHEMA_PUBLICATION}/${publication.id}/files`,
		)
		const list = await files.json()
		const rows = Array.isArray(list) ? list : list.results || []
		const annexRow = rows.find((row: { id: number }) => row.id === annex)
		expect(annexRow.accessUrl ?? null).toBeNull()
		expect(rows.some((row: { id: number }) => row.id === other)).toBe(true)

		const visibility = await admin.get(
			`${API_BASE}/publications/${publication.id}/visibility`,
		)
		expect((await visibility.json()).state).toBe('public')
	})
})
