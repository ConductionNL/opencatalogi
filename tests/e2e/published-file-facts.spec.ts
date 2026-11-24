/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * published-file-carries-its-facts REQ-PFF-002: the officer sees what the
 * publish wrote into each published file.
 *
 * WHAT THIS SPEC IS LOOKING FOR. A Woo batch whose publish stored one PDF
 * written and one signed PDF skipped (`wooPublication.embeddedTitles`, the
 * outcomes `EmbeddedTitleWriter` answers) shows, on the batch page under
 * "Published files", "Title written into the file" for the first and "Title
 * not written: the file is signed" for the second.
 *
 * WHY THE OUTCOMES ARE SEEDED. Producing a signed PDF and running a full
 * batch publish is the writer's own unit test (EmbeddedTitleWriterTest and
 * BatchPublicationWriterTest); this spec checks what the officer reads from
 * the stored outcomes.
 *
 * WHAT IT CREATES AND REMOVES. One batch, deleted in afterAll.
 *
 * @e2e openspec/changes/published-file-carries-its-facts/specs/woo-compliance/spec.md#scenario-the-officer-sees-what-was-written REQ-PFF-002
 * @spec openspec/changes/published-file-carries-its-facts/tasks.md#task-2.3
 */

import { expect, test } from './authenticated-request.ts'

const APP_BASE = '/apps/opencatalogi'
const API_BASE = '/index.php/apps/opencatalogi/api'
const BATCHES = '/index.php/apps/openregister/api/objects/publication/wooBatch'

test.describe('published-file-facts', () => {
	let batchId = ''

	test.afterAll(async ({ request }) => {
		if (batchId !== '') {
			await request.delete(`${BATCHES}/${batchId}`).catch(() => undefined)
		}
	})

	test('the officer sees what was written', async ({ page, request }) => {
		const reference = `WOO-E2E-PFF-${Date.now()}`
		const created = await request.post(`${API_BASE}/woo/batches`, {
			data: {
				caseReference: reference,
				documents: [{ documentReference: `${reference}-1`, fileName: 'Besluit.pdf', fileType: 'application/pdf' }],
			},
		})
		expect(created.status(), await created.text()).toBe(201)
		const batch = await created.json()
		batchId = String(batch.id ?? batch.uuid ?? batch['@self']?.id)

		const seeded = await request.patch(`${BATCHES}/${batchId}`, {
			data: {
				wooPublication: {
					embeddedTitles: {
						'Besluit.pdf': { status: 'written', hashBefore: 'a', hashAfter: 'b' },
						'Ondertekend.pdf': { status: 'skipped', hashBefore: 'c', hashAfter: 'c', reason: 'signed' },
					},
				},
			},
		})
		expect(seeded.ok(), await seeded.text()).toBe(true)

		await page.goto(`${APP_BASE}/woo/${batchId}`, { waitUntil: 'domcontentloaded' })
		const rows = page.getByTestId('woo-batch-embedded-title')
		await expect(rows).toHaveCount(2, { timeout: 15_000 })
		await expect(rows.filter({ hasText: 'Besluit.pdf' })).toContainText('Title written into the file')
		await expect(rows.filter({ hasText: 'Ondertekend.pdf' })).toContainText('Title not written: the file is signed')
	})
})
