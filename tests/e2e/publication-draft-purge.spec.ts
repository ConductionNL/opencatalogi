/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publication-lifecycle-on-or-draft-purge 1.2: an editor deletes a draft that
 * was never released permanently from its page, in its own dialog, and the
 * publication, its two files and its document reference are gone, with one
 * `publication.draft.purged` audit entry listing them.
 *
 * WHAT IT CREATES AND REMOVES. One catalog, one draft publication with two
 * files and one document reference. The purge removes the publication and
 * what hangs on it; cleanupAll removes the catalog and anything left over when
 * a step fails.
 *
 * NOT RUN BY THE BUILDER. Written from the change's scenario; it needs a live
 * instance and the Delete permanently action of task 1.2.
 *
 * @e2e openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md#scenario-a-draft-and-everything-on-it-goes
 * @spec openspec/changes/publication-lifecycle-on-or-draft-purge/specs/publications/spec.md
 */
import { expect, test } from './authenticated-request.ts'
import {
	Fixtures,
	REG_OPENCATALOGI,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

const APP_BASE = '/index.php/apps/opencatalogi'
const OBJECTS = '/index.php/apps/openregister/api/objects'
const DELETE_PERMANENTLY = /^\s*(Delete permanently|Definitief verwijderen)\s*$/

test.describe('Permanent delete of a draft', () => {
	const fx = new Fixtures()

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	// @e2e publications::a-draft-and-everything-on-it-goes
	test('a draft and everything on it goes', async ({ page, request: admin }) => {
		const slug = `purge-${Date.now().toString(36)}`
		await fx.createCatalog('purge', {
			slug,
			registers: [REG_OPENCATALOGI],
			schemas: [SCHEMA_PUBLICATION],
		})
		const publication = await fx.createPublication(
			'purge',
			{ status: 'draft' },
			REG_OPENCATALOGI,
		)
		const files = []
		for (const name of ['Besluit', 'Bijlage']) {
			files.push(
				await fx.attachFile(
					REG_OPENCATALOGI,
					SCHEMA_PUBLICATION,
					publication.id,
					`${name}.txt`,
					`Inhoud van ${name}`,
				),
			)
		}
		const reference = await admin.post(
			`${OBJECTS}/publication/documentReference`,
			{
				data: {
					title: 'Zaakdossier',
					publication: publication.id,
					sourceSystem: 'e2e',
				},
			},
		)
		expect(reference.ok(), `document reference: ${await reference.text()}`).toBe(
			true,
		)
		const referenceId = String((await reference.json())?.id ?? '')

		await page.goto(`${APP_BASE}/publications/${slug}/${publication.id}`)
		await page.getByRole('button', { name: DELETE_PERMANENTLY }).click()
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()
		await dialog.getByRole('button', { name: DELETE_PERMANENTLY }).click()
		await expect(dialog).toBeHidden()

		const gone = await admin.get(
			`${OBJECTS}/${REG_OPENCATALOGI}/${SCHEMA_PUBLICATION}/${publication.id}`,
		)
		expect(gone.status(), 'the publication cannot be read or restored').toBe(404)
		const referenceGone = await admin.get(
			`${OBJECTS}/publication/documentReference/${referenceId}`,
		)
		expect(referenceGone.status()).toBe(404)

		const trail = await admin.get(
			`/index.php/apps/openregister/api/audit-trails?object_uuid=${publication.id}`,
		)
		expect(trail.ok()).toBe(true)
		const entries = ((await trail.json())?.results ?? []) as Array<{
			action?: string
			changed?: Record<string, unknown>
		}>
		const purged = entries.filter(
			(entry) => entry.action === 'publication.draft.purged',
		)
		expect(purged, 'one summary audit entry').toHaveLength(1)
		const listed = JSON.stringify(purged[0].changed ?? {})
		for (const id of [publication.id, referenceId, ...files.map(String)]) {
			expect(listed).toContain(id)
		}
	})
})
