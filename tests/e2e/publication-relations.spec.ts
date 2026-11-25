/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two publications linked by `replaces` read the relation from both sides on
 * the publication page: the Related panel groups the newer one's link under
 * "replaces" and the older one's under "is replaced by", the labels
 * OpenRegister answers for the direction read.
 *
 * WHAT THIS SPEC IS LOOKING FOR. The group heading of the Related panel and
 * the title of the publication on the other side, on the detail page. Storing and validating the
 * relation is OpenRegister's and covered by the unit tests
 * (PublicationRelationsResponseTest, PublicationRelationCheckTest).
 *
 * Base URL and credentials come from the environment (playwright.config.ts).
 *
 * @e2e openspec/changes/publication-relations-place-and-source-ids/specs/publications/spec.md#scenario-both-sides-read-the-relation REQ-PRS-001
 */
import type { Locator, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog } from './publication-catalog.ts'
import { Fixtures, REG_PUBLICATION } from './workflows/_fixtures.ts'

/** The app's page base path. */
const APP_BASE = '/index.php/apps/opencatalogi'

/** The heading of the group on the newer publication. */
const REPLACES = /^\s*(replaces|vervangt)\b/i

/** The heading of the group on the older publication. */
const REPLACED_BY = /^\s*(is replaced by|wordt vervangen door)\b/i

/**
 * Scroll the page down until the locator shows: the detail grid mounts a
 * widget only when it scrolls into view.
 *
 * @param page The page.
 * @param target What has to become visible.
 */
async function scrollUntilVisible(page: Page, target: Locator): Promise<void> {
	await page.mouse.move(800, 400)
	await expect(async () => {
		await page.mouse.wheel(0, 400)
		await expect(target).toBeVisible({ timeout: 500 })
	}).toPass({ timeout: 20000 })
}

test.describe('Publication relations', () => {
	const fx = new Fixtures()

	test.beforeAll(async () => {
		await fx.init()
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('both sides read the relation', async ({ request: admin, page }) => {
		const catalog = await publicationCatalog(admin, 'e2e-relations')
		const older = await fx.createPublication(
			'relations-older',
			{ publicationDate: '2026-01-05T09:00:00+00:00' },
			REG_PUBLICATION,
		)
		const newer = await fx.createPublication(
			'relations-newer',
			{
				publicationDate: '2026-02-05T09:00:00+00:00',
				replaces: [older.id],
			},
			REG_PUBLICATION,
		)

		const pagePath = (id: string): string =>
			`${APP_BASE}/publications/${encodeURIComponent(catalog.slug)}/${id}`

		await page.goto(pagePath(newer.id))
		const newerPage = page.locator('[data-testid="cn-detail-page"]').first()
		await expect(newerPage).toBeVisible({ timeout: 15000 })
		await scrollUntilVisible(page, newerPage.getByText(REPLACES).first())
		await expect(newerPage).toContainText(older.title)

		await page.goto(pagePath(older.id))
		const olderPage = page.locator('[data-testid="cn-detail-page"]').first()
		await expect(olderPage).toBeVisible({ timeout: 15000 })
		await scrollUntilVisible(page, olderPage.getByText(REPLACED_BY).first())
		await expect(olderPage).toContainText(newer.title)
	})
})
