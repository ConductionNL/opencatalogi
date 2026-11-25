/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-review-surface: the reviewer sees each document of a batch as the public
 * will (REQ-WRV-001), a rejected batch goes back to its author with the reason
 * (REQ-WRV-002), and the reviewer reads the redaction log (REQ-WRV-003).
 *
 * WHAT IT SETS UP. Two small PDFs are uploaded over WebDAV into the signed-in
 * user's files; a batch is created with them, one assessed `openbaar` and one
 * `deels_openbaar` with a verified redaction (the second file stands in for the
 * redacted copy) and a two-entry redaction log, written through OpenRegister's
 * object API as the redaction step stores it. The batch is then marked ready
 * for review.
 *
 * WHAT IT CREATES AND REMOVES. The batch and the two files, removed in afterAll.
 *
 * @e2e openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#scenario-the-reviewer-sees-each-document-as-the-public-will REQ-WRV-001
 * @e2e openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#scenario-a-rejected-batch-goes-back-to-its-author-with-the-reason REQ-WRV-002
 * @e2e openspec/changes/woo-review-surface/specs/woo-transparency/spec.md#scenario-the-reviewer-reads-the-log REQ-WRV-003
 * @spec openspec/changes/woo-review-surface/tasks.md#task-2.2
 * @spec openspec/changes/woo-review-surface/tasks.md#task-3.3
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'

const APP_BASE = '/apps/opencatalogi'
const API_BASE = '/index.php/apps/opencatalogi/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication'
const RUN = Date.now().toString(36)
const FOLDER = `E2E-review-${RUN}`

/** How many batches this file seeded, for a case reference of each its own. */
let seeded = 0
const PDF = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'
const REASON = 'Bijlage 3 bevat nog een BSN'

/**
 * The signed-in user's id.
 *
 * @param request The signed-in request context.
 * @return The uid.
 */
async function whoami(request: APIRequestContext): Promise<string> {
	const res = await request.get('/ocs/v2.php/cloud/user?format=json', { headers: { 'OCS-APIRequest': 'true' } })
	expect(res.ok(), await res.text()).toBe(true)
	return String((await res.json())?.ocs?.data?.id ?? '')
}

/**
 * A batch ready for review with an openbaar and a verified deels_openbaar document.
 *
 * @param request The signed-in request context.
 * @param uid The signed-in user.
 * @return The batch id and its reference.
 */
async function seedBatchInReview(request: APIRequestContext, uid: string): Promise<{ id: string, reference: string }> {
	const dav = `/remote.php/dav/files/${uid}/${FOLDER}`
	await request.fetch(dav, { method: 'MKCOL' })
	for (const name of ['Raadsbesluit.pdf', 'Notitie-gelakt.pdf']) {
		const put = await request.put(`${dav}/${name}`, { data: PDF, headers: { 'Content-Type': 'application/pdf' } })
		expect(put.status(), `uploading ${name}`).toBeLessThan(300)
	}

	// One reference per batch: a case reference names one batch (409 on a second).
	seeded += 1
	const reference = `WOO-E2E-WRV-${RUN}-${seeded}`
	const created = await request.post(`${API_BASE}/woo/batches`, {
		data: {
			caseReference: reference,
			documents: [
				{ documentReference: `/${FOLDER}/Raadsbesluit.pdf`, fileName: 'Raadsbesluit.pdf', fileType: 'application/pdf' },
				{ documentReference: `/${FOLDER}/Notitie-gelakt.pdf`, fileName: 'Notitie wethouder.pdf', fileType: 'application/pdf' },
			],
		},
	})
	expect(created.status(), await created.text()).toBe(201)
	const batch = await created.json()
	const id = String(batch.id ?? batch.uuid ?? batch['@self']?.id)

	const listed = await request.get(`${OBJECTS}/wooAssessment`, { params: { caseReference: reference, _limit: '10' } })
	const rows = ((await listed.json())?.results ?? []) as Array<Record<string, any>>
	for (const row of rows) {
		const rowId = String(row.id ?? row['@self']?.id)
		const data = row.fileName === 'Raadsbesluit.pdf'
			? { assessment: 'openbaar' }
			: {
					assessment: 'deels_openbaar',
					weigeringsgronden: ['5.1.2.e'],
					redactionStatus: 'verified',
					anonymizedDocument: `/${FOLDER}/Notitie-gelakt.pdf`,
					redactionLog: [
						{ relationId: 1, entityType: 'PERSON', positionStart: 10, positionEnd: 22, grounds: ['5.1.2.e'] },
						{ relationId: 2, entityType: 'PHONE_NUMBER', positionStart: 40, positionEnd: 50, grounds: ['5.1.2.e'] },
					],
				}
		const saved = await request.patch(`${OBJECTS}/wooAssessment/${rowId}`, { data })
		expect(saved.ok(), await saved.text()).toBe(true)
	}

	const ready = await request.post(`${API_BASE}/woo/batches/${id}/ready-for-review`, { data: {} })
	expect(ready.ok(), await ready.text()).toBe(true)
	return { id, reference }
}

test.describe('woo-review-surface', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	let uid = ''
	const batches: string[] = []

	test.beforeAll(async ({ request }) => {
		uid = await whoami(request)
	})

	test.afterAll(async ({ request }) => {
		for (const id of batches) {
			await request.delete(`${OBJECTS}/wooBatch/${id}`).catch(() => undefined)
		}
		if (uid !== '') {
			await request.fetch(`/remote.php/dav/files/${uid}/${FOLDER}`, { method: 'DELETE' }).catch(() => undefined)
		}
	})

	test('the reviewer sees each document as the public will', async ({ page, request, context }) => {
		const batch = await seedBatchInReview(request, uid)
		batches.push(batch.id)

		await page.goto(`${APP_BASE}/woo/${batch.id}`, { waitUntil: 'domcontentloaded' })
		const review = page.locator('.woo-review')
		await expect(review).toBeVisible({ timeout: 15_000 })
		await expect(review.locator('.woo-review__document')).toHaveCount(2)

		const approve = review.getByRole('button', { name: 'Approve' })
		await expect(approve).toBeDisabled()

		// The links themselves: the note above them names the same words.
		const views = review.getByRole('link', { name: 'Open the public view' })
		await expect(views).toHaveCount(2)
		for (const index of [0, 1]) {
			const opened = context.waitForEvent('page')
			await views.nth(index).click()
			await (await opened).close()
		}
		await expect(review.locator('.woo-review__seen')).toHaveCount(2)
		await expect(approve).toBeEnabled()
	})

	test('a rejected batch goes back to its author with the reason', async ({ page, request }) => {
		const batch = await seedBatchInReview(request, uid)
		batches.push(batch.id)

		await page.goto(`${APP_BASE}/woo/${batch.id}`, { waitUntil: 'domcontentloaded' })
		const review = page.locator('.woo-review')
		await expect(review).toBeVisible({ timeout: 15_000 })
		await review.getByRole('button', { name: 'Reject' }).click()

		const dialog = page.locator('.woo-reject-dialog')
		await expect(dialog).toBeVisible()
		await dialog.getByRole('textbox').fill(REASON)
		await page.getByRole('dialog').getByRole('button', { name: 'Reject' }).click()

		await expect(page.locator('.woo-batch__rejection')).toContainText(REASON, { timeout: 15_000 })
		const stored = await (await request.get(`${OBJECTS}/wooBatch/${batch.id}`)).json()
		expect(stored.status).toBe('in_progress')
		expect(String(stored.assignee ?? '')).toBe(uid)
	})

	test('the reviewer reads the log', async ({ page, request }) => {
		const batch = await seedBatchInReview(request, uid)
		batches.push(batch.id)

		await page.goto(`${APP_BASE}/woo/${batch.id}`, { waitUntil: 'domcontentloaded' })
		const log = page.locator('.woo-redaction-log')
		await expect(log).toBeVisible({ timeout: 15_000 })
		const rows = log.locator('tbody tr')
		await expect(rows).toHaveCount(2)
		await expect(rows.nth(0)).toContainText('5.1.2.e')
		await expect(log).toContainText('PHONE_NUMBER')
	})
})
