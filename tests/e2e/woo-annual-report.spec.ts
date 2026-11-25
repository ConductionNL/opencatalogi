/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An officer generates the Woo annual report from the publications report
 * (board OcRapportGebruik). The report is OpenRegister's to render: the
 * woo-jaarverslag template is a seed of its report-templates schema, and
 * POST /api/reports/generate renders it. On an OpenRegister without report
 * templates the page says the report needs a newer OpenRegister, and this spec
 * checks that sentence instead of a download.
 *
 * WHAT THIS SPEC IS LOOKING FOR. Publications in 2025 and 2026 are seeded; the
 * officer picks 2026 and PDF and presses Generate annual report; a PDF
 * downloads. The figures inside the PDF are covered by WooAnnualReportTemplateTest
 * and OpenRegister's ReportTemplateGeneratorTest.
 *
 * Base URL and credentials come from the environment (playwright.config.ts).
 *
 * @e2e openspec/changes/annual-report/specs/woo-compliance/spec.md#scenario-an-officer-generates-the-2026-annual-report
 */
import type { SeededObject } from './workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import { bootApp, navToRoute } from './spec-coverage/_nav.ts'
import { Fixtures } from './workflows/_fixtures.ts'

const fx = new Fixtures()
const seeded: SeededObject[] = []

const GENERATE = /^\s*(Generate annual report|Jaarverslag maken)\s*$/
const NEEDS_NEWER =
	/(The Woo annual report needs a newer OpenRegister\.|Het Woo-jaarverslag vraagt een nieuwere OpenRegister\.)/

test.beforeAll(async () => {
	await fx.init()
	seeded.push(
		await fx.createPublication('annual 2025', {
			status: 'published',
			publicationDate: '2025-06-01T10:00:00+00:00',
			wooCategory: 'infocat012',
			creationDate: '2025-05-20',
		}),
	)
	seeded.push(
		await fx.createPublication('annual 2026', {
			status: 'published',
			publicationDate: '2026-03-01T10:00:00+00:00',
			wooCategory: 'infocat016',
		}),
	)
})

test.afterAll(async () => {
	await fx.cleanupAll()
	await fx.dispose()
})

test.describe('Woo annual report @e2e REQ-WAR-002', () => {
	test('an officer generates the 2026 Woo annual report', async ({ page }) => {
		await bootApp(page)
		await navToRoute(page, '/reports/publications')

		const widget = page.locator('[data-testid="annual-report"]')
		await expect(widget).toBeVisible({ timeout: 20000 })

		const unavailable = widget.locator(
			'[data-testid="annual-report-unavailable"]',
		)
		if (await unavailable.isVisible()) {
			await expect(unavailable).toHaveText(NEEDS_NEWER)
			await expect(widget.getByRole('button', { name: GENERATE })).toHaveCount(
				0,
			)
			return
		}

		const download = page.waitForEvent('download')
		await widget.getByRole('button', { name: GENERATE }).click()
		const file = await download

		expect(file.suggestedFilename()).toMatch(/2026.*\.pdf$/)
		await expect(
			widget.locator('[data-testid="annual-report-failed"]'),
		).toHaveCount(0)
	})
})
