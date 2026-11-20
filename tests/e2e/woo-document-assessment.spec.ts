/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * woo-document-assessment-screen task 5.1 (REQ-WDA-001 to REQ-WDA-004): an
 * officer opens a Woo batch from the Woo index, moves its documents between
 * the four assessment columns through each card's action menu (the keyboard
 * path), is asked for a refusal ground before a Partly public or Not public
 * save and saves nothing without one, and, for a partly public document whose
 * redaction ran, compares the two versions and marks the redaction verified.
 *
 * The batch is created through `POST /api/woo/batches` with three documents.
 * The redaction scenarios need the redaction pipeline (another app) to have
 * produced a redacted file; on an instance without it they skip, saying so.
 * The fail-closed publish and the 409 on a failed redaction are `@e2e
 * exclude` in the spec and covered by PHPUnit.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'

const APP_BASE = '/apps/opencatalogi'
const API_BASE = '/index.php/apps/opencatalogi/api'
const ASSESSMENTS = '/index.php/apps/openregister/api/objects/publication/wooAssessment'

type Assessment = Record<string, any>

/**
 * A batch of three documents for a fresh case reference.
 *
 * @param request The authenticated request fixture.
 * @return The batch id, its case reference and its assessments.
 */
async function seedBatch(request: APIRequestContext): Promise<{ id: string, reference: string, documents: Assessment[] }> {
	const reference = `WOO-E2E-${Date.now()}`
	const created = await request.post(`${API_BASE}/woo/batches`, {
		data: {
			caseReference: reference,
			documents: [
				{ documentReference: `${reference}-1`, fileName: 'Email thread with residents.pdf', fileType: 'application/pdf' },
				{ documentReference: `${reference}-2`, fileName: 'Raadsbesluit.pdf', fileType: 'application/pdf' },
				{ documentReference: `${reference}-3`, fileName: 'Notitie wethouder.pdf', fileType: 'application/pdf' },
			],
		},
	})
	expect(created.status(), 'creating a batch must succeed').toBe(201)
	const batch = await created.json()
	const id = String(batch.id ?? batch.uuid ?? batch['@self']?.id)
	return { id, reference, documents: await assessments(request, reference) }
}

/**
 * The batch's assessments, the `wooAssessment` objects of its case, as the
 * batch page reads them (the batch itself holds only their ids).
 *
 * @param request The authenticated request fixture.
 * @param reference The batch's case reference.
 * @return The assessments.
 */
async function assessments(request: APIRequestContext, reference: string): Promise<Assessment[]> {
	const resp = await request.get(ASSESSMENTS, { params: { caseReference: reference, _limit: '50' } })
	expect(resp.status()).toBe(200)
	return ((await resp.json()).results ?? []) as Assessment[]
}

/**
 * The assessment of one document by file name.
 *
 * @param request The authenticated request fixture.
 * @param reference The batch's case reference.
 * @param fileName The file name.
 * @return The assessment.
 */
async function assessmentOf(request: APIRequestContext, reference: string, fileName: string): Promise<Assessment> {
	const found = (await assessments(request, reference)).find((row) => row.fileName === fileName)
	expect(found, `the batch holds ${fileName}`).toBeTruthy()
	return found as Assessment
}

/**
 * A column of the batch page by its title.
 *
 * @param page The page.
 * @param title The column title.
 * @return The column.
 */
function column(page: Page, title: RegExp) {
	return page.locator('.woo-batch__column').filter({ has: page.locator('.woo-batch__column-title', { hasText: title }) })
}

/**
 * Choose "Move to {column}" in a card's action menu, from the keyboard.
 *
 * @param page The page.
 * @param fileName The card's file name.
 * @param target The column to move to.
 * @return Nothing.
 */
async function moveWithKeyboard(page: Page, fileName: string, target: string): Promise<void> {
	const menu = page.getByRole('button', { name: `Actions for ${fileName}` })
	await menu.focus()
	await page.keyboard.press('Enter')
	const item = page.getByRole('menuitem', { name: `Move to ${target}` })
	await expect(item).toBeVisible()
	await item.focus()
	await page.keyboard.press('Enter')
}

test.describe('woo-document-assessment', () => {
	// @e2e woo-transparency::an-officer-opens-a-batch
	test('a batch opens from its row on the Woo batches page', async ({ page, request }) => {
		const batch = await seedBatch(request)
		await page.goto(`${APP_BASE}/woo`, { waitUntil: 'domcontentloaded' })
		await page.getByText(batch.reference).first().click()
		await expect(page).toHaveURL(new RegExp(`/woo/${batch.id}$`), { timeout: 15_000 })
		await expect(page.locator('.woo-batch')).toContainText(batch.reference, { timeout: 15_000 })
		await expect(column(page, /To assess/).locator('.woo-batch__card')).toHaveCount(3)
	})

	// @e2e woo-transparency::a-keyboard-user-moves-a-card
	test('a card moved to Public from its action menu is saved as openbaar', async ({ page, request }) => {
		const batch = await seedBatch(request)
		await page.goto(`${APP_BASE}/woo/${batch.id}`, { waitUntil: 'domcontentloaded' })
		await expect(column(page, /To assess/).locator('.woo-batch__card')).toHaveCount(3, { timeout: 15_000 })

		const saved = page.waitForResponse((response) => response.request().method() === 'PUT'
			&& response.url().includes(`/api/woo/batches/${batch.id}/documents/`))
		await moveWithKeyboard(page, 'Raadsbesluit.pdf', 'Public')
		expect((await saved).ok()).toBeTruthy()

		await expect(column(page, /^Public/).locator('.woo-batch__card')).toContainText('Raadsbesluit.pdf')
		await expect(page.getByRole('status').or(page.locator('[aria-live]')).first()).toBeAttached()
		expect((await assessmentOf(request, batch.reference, 'Raadsbesluit.pdf')).assessment).toBe('openbaar')
	})

	// @e2e woo-transparency::no-ground-no-save
	test('a move to Not public closed without a ground saves nothing', async ({ page, request }) => {
		const batch = await seedBatch(request)
		await page.goto(`${APP_BASE}/woo/${batch.id}`, { waitUntil: 'domcontentloaded' })
		await expect(column(page, /To assess/).locator('.woo-batch__card')).toHaveCount(3, { timeout: 15_000 })

		let saves = 0
		page.on('request', (sent) => {
			if (sent.method() === 'PUT' && sent.url().includes(`/api/woo/batches/${batch.id}/documents/`)) {
				saves++
			}
		})
		await moveWithKeyboard(page, 'Notitie wethouder.pdf', 'Not public')
		const dialog = page.getByRole('dialog')
		await expect(dialog.getByLabel('Refusal grounds')).toBeVisible()
		await expect(dialog.getByRole('button', { name: 'Save assessment' })).toBeDisabled()
		await dialog.getByRole('button', { name: 'Cancel' }).click()

		await expect(column(page, /To assess/).locator('.woo-batch__card')).toContainText('Notitie wethouder.pdf')
		expect(saves).toBe(0)
		expect((await assessmentOf(request, batch.reference, 'Notitie wethouder.pdf')).assessment).toBe('te_beoordelen')
	})

	// @e2e woo-transparency::assessing-a-document-as-partly-public-runs-the-redaction
	test('a move to Partly public with a ground is saved with that ground and shows the redaction state', async ({ page, request }) => {
		const batch = await seedBatch(request)
		await page.goto(`${APP_BASE}/woo/${batch.id}`, { waitUntil: 'domcontentloaded' })
		await expect(column(page, /To assess/).locator('.woo-batch__card')).toHaveCount(3, { timeout: 15_000 })

		await moveWithKeyboard(page, 'Email thread with residents.pdf', 'Partly public')
		const dialog = page.getByRole('dialog')
		await dialog.getByLabel('Refusal grounds').click()
		await page.getByRole('option', { name: /5\.1.*(lid )?2.*e/i }).first().click()
		await dialog.getByRole('button', { name: 'Save assessment' }).click()

		const card = column(page, /Partly public/).locator('.woo-batch__card', { hasText: 'Email thread with residents.pdf' })
		await expect(card).toBeVisible({ timeout: 15_000 })
		await expect(card.locator('.woo-batch__chip').first()).toBeVisible()
		await expect(card.locator('.woo-batch__redaction')).toBeVisible()

		const stored = await assessmentOf(request, batch.reference, 'Email thread with residents.pdf')
		expect(stored.assessment).toBe('deels_openbaar')
		expect(JSON.stringify(stored.weigeringsgronden)).toMatch(/5\.1/)
	})

	// @e2e woo-transparency::the-officer-compares-the-two-versions
	// @e2e woo-transparency::the-officer-verifies-and-the-batch-can-go-out
	test('the redaction page switches versions and records who verified it', async ({ page, request }) => {
		const batch = await seedBatch(request)
		const doc = batch.documents.find((row) => row.fileName === 'Email thread with residents.pdf') as Assessment
		const docId = String(doc.id ?? doc.uuid ?? doc['@self']?.id)
		const grounds = await (await request.get(`${API_BASE}/woo/weigeringsgronden`)).json()
		const ground = (grounds.results as Array<Record<string, any>>)[0]
		const put = await request.put(`${API_BASE}/woo/batches/${batch.id}/documents/${docId}`, {
			data: { assessment: 'deels_openbaar', weigeringsgronden: [ground.article] },
		})
		expect(put.ok()).toBeTruthy()

		let redacted: Assessment | undefined
		await expect.poll(async () => {
			redacted = await assessmentOf(request, batch.reference, 'Email thread with residents.pdf')
			return String(redacted.redactionStatus ?? '')
		}, { timeout: 60_000 }).not.toBe('pending')
		test.skip(redacted?.redactionStatus !== 'verified', 'no redaction pipeline on this instance produced a redacted file')

		await page.goto(`${APP_BASE}/woo/${batch.id}/documents/${docId}`, { waitUntil: 'domcontentloaded' })
		const view = page.locator('.woo-redaction')
		await expect(view.locator('.woo-redaction__counts')).toContainText(/kept/)
		await view.getByRole('button', { name: 'Original' }).click()
		await expect(view.locator('.woo-redaction__viewer, .woo-redaction__preview').first()).toBeVisible()

		await view.getByRole('button', { name: 'Mark redaction verified' }).click()
		await expect(view.locator('.woo-redaction__steps')).toContainText(/admin/i, { timeout: 15_000 })
		const after = await assessmentOf(request, batch.reference, 'Email thread with residents.pdf')
		expect(after.redactionVerifiedBy).toBeTruthy()
		expect(after.redactionVerifiedAt).toBeTruthy()
	})
})
