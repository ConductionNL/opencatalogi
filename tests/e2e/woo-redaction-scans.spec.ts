/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Woo redaction of scans: the officer sees, per document on the batch page,
 * whether OCR ran, the engine's verdict and whether the text can be searched.
 *
 * WHAT THIS SPEC IS LOOKING FOR. A batch holds a scanned letter and a
 * born-digital PDF, both assessed `deels_openbaar` and redacted. On the batch
 * page the scanned letter's card says OCR ran, the verdict is clean and its
 * text can be searched; the born-digital card says OCR was not needed, the
 * verdict is clean and its text can be searched.
 *
 * WHAT IT SETS UP. Two small PDFs are uploaded over WebDAV into the signed-in
 * user's files and a batch is created with them through
 * `POST /api/woo/batches`. Both assessments are then written through
 * OpenRegister's object API as the redaction step stores them: `ocr`
 * (`{ran, confidence, words}`, REQ-WRT-001) and `verification` (the engine's
 * verdict, REQ-WRT-002) are the names the spec gives; `finalVerification` (the
 * second verdict on the final bytes) and `textLayer` (`{pages, pagesWithText}`,
 * the keys filinq's `OcrService::addTextLayer()` returns) are this spec's names
 * for what REQ-WRT-003 stores, and the build must use them or change them here.
 * Running the real pipeline needs filinq, Tesseract and OpenRegister's image
 * seam, and is covered by the PHPUnit tests and the live check of task 3.4;
 * this spec covers what the officer reads.
 *
 * DEPENDS ON. `woo-redaction-scans-and-text-layer` task 3.3 (the state per
 * document on `WooBatchDetail.vue`), on top of tasks 1.1 and 3.1 that store the
 * verdicts and the text check. The card shows three lines: "OCR ran" or "OCR
 * not needed", "Verdict: clean" (or the verdict it holds), and "Text can be
 * searched" or "Text cannot be searched". Until task 3.3 is on the instance
 * this spec fails at its first card assertion; it is written now from the
 * change's scenario so the build that adds it has its acceptance test waiting.
 *
 * WHAT IT CREATES AND REMOVES. The batch, its assessments and the two files,
 * removed in afterAll.
 *
 * @e2e openspec/changes/woo-redaction-scans-and-text-layer/specs/woo-transparency/spec.md#scenario-the-officer-sees-the-state-per-document REQ-WRT-003
 * @spec openspec/changes/woo-redaction-scans-and-text-layer/tasks.md#task-3.3
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'

const APP_BASE = '/index.php/apps/opencatalogi'
const API_BASE = '/index.php/apps/opencatalogi/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication'
const RUN = Date.now().toString(36)
const FOLDER = `E2E-scans-${RUN}`

/** A minimal PDF; the bytes are not read, the stored state is. */
const PDF = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n'

/** The scanned document of the scenario. */
const SCAN = 'Brief bewoner (scan).pdf'

/** The born-digital document of the scenario. */
const BORN_DIGITAL = 'Raadsvoorstel.pdf'

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
 * A batch with a redacted scan and a redacted born-digital document.
 *
 * @param request The signed-in request context.
 * @param uid The signed-in user.
 * @return The batch id.
 */
async function seedBatch(request: APIRequestContext, uid: string): Promise<string> {
	const dav = `/remote.php/dav/files/${uid}/${FOLDER}`
	await request.fetch(dav, { method: 'MKCOL' })
	for (const name of ['scan.pdf', 'scan-gelakt.pdf', 'voorstel.pdf', 'voorstel-gelakt.pdf']) {
		const put = await request.put(`${dav}/${name}`, { data: PDF, headers: { 'Content-Type': 'application/pdf' } })
		expect(put.status(), `uploading ${name}`).toBeLessThan(300)
	}

	const reference = `WOO-E2E-WRT-${RUN}`
	const created = await request.post(`${API_BASE}/woo/batches`, {
		data: {
			caseReference: reference,
			documents: [
				{ documentReference: `/${FOLDER}/scan.pdf`, fileName: SCAN, fileType: 'application/pdf' },
				{ documentReference: `/${FOLDER}/voorstel.pdf`, fileName: BORN_DIGITAL, fileType: 'application/pdf' },
			],
		},
	})
	expect(created.status(), await created.text()).toBe(201)
	const batch = await created.json()
	const id = String(batch.id ?? batch.uuid ?? batch['@self']?.id)

	const listed = await request.get(`${OBJECTS}/wooAssessment`, { params: { caseReference: reference, _limit: '10' } })
	expect(listed.ok(), await listed.text()).toBe(true)
	const rows = ((await listed.json())?.results ?? []) as Array<Record<string, any>>
	expect(rows, 'the batch holds both documents').toHaveLength(2)
	for (const row of rows) {
		const rowId = String(row.id ?? row['@self']?.id)
		const scanned = row.fileName === SCAN
		const data = {
			assessment: 'deels_openbaar',
			weigeringsgronden: ['5.1.2.e'],
			redactionStatus: 'verified',
			anonymizedDocument: `/${FOLDER}/${scanned ? 'scan-gelakt.pdf' : 'voorstel-gelakt.pdf'}`,
			ocr: scanned ? { ran: true, confidence: 91, words: 212 } : { ran: false },
			verification: { verdict: 'clean' },
			finalVerification: scanned ? { verdict: 'clean' } : null,
			textLayer: { pages: 1, pagesWithText: 1 },
		}
		const saved = await request.patch(`${OBJECTS}/wooAssessment/${rowId}`, { data })
		expect(saved.ok(), await saved.text()).toBe(true)
	}
	return id
}

/**
 * One document's card on the batch page.
 *
 * @param page The page.
 * @param fileName The document's file name.
 * @return The card.
 */
function card(page: Page, fileName: string) {
	return page.getByRole('listitem').filter({ hasText: fileName })
}

test.describe('woo-redaction-scans', () => {
	test.setTimeout(120_000)

	let uid = ''
	let batchId = ''

	test.beforeAll(async ({ request }) => {
		uid = await whoami(request)
	})

	test.afterAll(async ({ request }) => {
		if (batchId !== '') {
			const listed = await request.get(`${OBJECTS}/wooAssessment`, { params: { caseReference: `WOO-E2E-WRT-${RUN}`, _limit: '10' } })
			const rows = listed.ok() ? (((await listed.json())?.results ?? []) as Array<Record<string, any>>) : []
			for (const row of rows) {
				await request.delete(`${OBJECTS}/wooAssessment/${row.id ?? row['@self']?.id}`).catch(() => undefined)
			}
			await request.delete(`${OBJECTS}/wooBatch/${batchId}`).catch(() => undefined)
		}
		if (uid !== '') {
			await request.fetch(`/remote.php/dav/files/${uid}/${FOLDER}`, { method: 'DELETE' }).catch(() => undefined)
		}
	})

	test('the officer sees the state per document', async ({ page, request }) => {
		batchId = await seedBatch(request, uid)

		await page.goto(`${APP_BASE}/woo/${batchId}`, { waitUntil: 'domcontentloaded' })

		const scan = card(page, SCAN)
		await expect(scan).toBeVisible({ timeout: 15_000 })
		await expect(scan).toContainText('OCR ran')
		await expect(scan).toContainText('Verdict: clean')
		await expect(scan).toContainText('Text can be searched')

		const bornDigital = card(page, BORN_DIGITAL)
		await expect(bornDigital).toBeVisible()
		await expect(bornDigital).toContainText('OCR not needed')
		await expect(bornDigital).not.toContainText('OCR ran')
		await expect(bornDigital).toContainText('Verdict: clean')
		await expect(bornDigital).toContainText('Text can be searched')
	})
})
