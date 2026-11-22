/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The file list of a publication says, per attachment, whether search can
 * read it (board OcPublicatieBestanden, Text column), and offers "Read again"
 * to an editor.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The words of the Text column for a typed PDF
 * and for a scan on an instance without filinq. The reading job itself runs
 * from cron and is covered by AttachmentReadingJobTest, so this spec seeds the
 * two reading states the job writes on such an instance instead of waiting on
 * cron: `typed` for the PDF and `unavailable` for the scan.
 *
 * Base URL and credentials come from the environment (playwright.config.ts).
 *
 * @e2e openspec/changes/scanned-attachments-become-searchable/specs/search/spec.md#scenario-an-editor-sees-which-scan-search-cannot-read
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { SeededObject } from './workflows/_fixtures.ts'

import { expect, request, test } from '@playwright/test'
import { bootApp, navToRoute } from './spec-coverage/_nav.ts'
import { rowAction, waitIndexBody } from './workflows/_crud.ts'
import {
	ADMIN_PASS,
	ADMIN_USER,
	BASE,
	Fixtures,
	REG_PUBLICATION,
	SCHEMA_PUBLICATION,
} from './workflows/_fixtures.ts'

const fx = new Fixtures()

const TYPED = /^\s*(Typed text|Getypte tekst)\s*$/
const NO_OCR = /^\s*(No text: OCR is not installed|Geen tekst: OCR is niet geïnstalleerd)\s*$/
const READ_AGAIN = /^\s*(Read again|Opnieuw lezen)\s*$/

let api: APIRequestContext
let slug: string
let publication: SeededObject
const readingIds: string[] = []

/**
 * Record one reading state as the job would.
 *
 * @param fileId The attachment's file id.
 * @param state The reading state.
 * @param reason Why, when the state alone does not say it.
 */
async function seedReading(fileId: number, state: string, reason = ''): Promise<void> {
	const res = await api.post(
		`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/attachmentReading`,
		{
			data: {
				fileId,
				publication: publication.id,
				state,
				reason,
				updatedAt: new Date().toISOString(),
			},
		},
	)
	expect(res.ok(), `seeding the ${state} reading: ${res.status()}`).toBeTruthy()
	const body = await res.json()
	readingIds.push(String(body.id ?? body['@self']?.id))
}

/**
 * Open the publication's file list from its row menu.
 *
 * @param page The page.
 */
async function openFileList(page: Page) {
	await bootApp(page)
	await navToRoute(page, `/publications/${encodeURIComponent(slug)}`)
	await waitIndexBody(page)
	await rowAction(page, publication.title, 'file-list')
	const modal = page
		.getByRole('dialog')
		.filter({ has: page.locator('.viewObjectDialog') })
	await expect(modal).toBeVisible({ timeout: 15000 })
	return modal
}

test.beforeAll(async () => {
	await fx.init()
	api = await request.newContext({
		baseURL: BASE,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
	slug = `${fx.prefix}-reading`
	await fx.createCatalog('Attachment reading', { slug })
	publication = await fx.createPublication('Council decision with a scanned annex')
	const pdf = await fx.attachFile(
		REG_PUBLICATION,
		SCHEMA_PUBLICATION,
		publication.id,
		'decision.txt',
		'The council decided to renovate the swimming pool.',
	)
	const scan = await fx.attachFile(
		REG_PUBLICATION,
		SCHEMA_PUBLICATION,
		publication.id,
		'annex-scan.txt',
		' ',
	)
	await seedReading(pdf, 'typed')
	await seedReading(scan, 'unavailable', 'OCR is not installed')
})

test.afterAll(async () => {
	for (const id of readingIds) {
		await api
			.delete(`/index.php/apps/openregister/api/objects/${REG_PUBLICATION}/attachmentReading/${id}`)
			.catch(() => {})
	}
	await api?.dispose()
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('the Text column of the file list', () => {
	test('an editor sees which scan search cannot read', async ({ page }) => {
		const modal = await openFileList(page)

		await expect(modal.getByRole('columnheader', { name: /^\s*(Text|Tekst)\s*$/ })).toBeVisible()
		const pdfRow = modal.getByRole('row').filter({ hasText: 'decision.txt' })
		const scanRow = modal.getByRole('row').filter({ hasText: 'annex-scan.txt' })
		await expect(pdfRow.locator('td.table-row-text')).toHaveText(TYPED)
		await expect(scanRow.locator('td.table-row-text')).toHaveText(NO_OCR)

		await scanRow.getByRole('button', { name: /Actions for/ }).click()
		await expect(page.getByRole('menuitem', { name: READ_AGAIN })).toBeVisible()
	})
})
