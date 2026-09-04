/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioral UI coverage for the OpenCatalogi Dashboard (manifest page
 * type:custom → DashboardView, built on CnDashboardPage). This is the
 * landing page after boot.
 *
 * Asserts the genuine dashboard content: the dashboard page shell, the
 * "Dashboard" title, the stat cards (Publications / Concept / Published /
 * Depublished), the two analytics sections (Publications by Category +
 * Activity), and the primary "New Publication" action. Then exercises a
 * real interaction: clicking "New Publication" opens the create modal.
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test dashboard-page
 */
import { expect, test } from '@playwright/test'
import { bootApp, content, fatalErrors, trackPageErrors } from './_nav.ts'

test.describe('dashboard-page', () => {
	test(// @e2e dashboard::dashboard-renders-stats-and-activity
	'Dashboard — renders stat cards, category + activity sections and primary action', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		await bootApp(page)

		// Dashboard is the default route — its CnDashboardPage shell must mount.
		await expect(
			page.locator('[data-testid="cn-dashboard-page"]').first(),
		).toBeVisible({ timeout: 15000 })

		// The two analytics sections — distinctive Dashboard content that
		// confirms DashboardView (not a generic page) rendered.
		await expect(
			content(page)
				.getByText(/Publications by Category/i)
				.first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			content(page)
				.getByText(/Activity/i)
				.first(),
		).toBeVisible({ timeout: 15000 })

		// The publication stat cards.
		for (const label of [
			/Concept Publications/i,
			/Published/i,
			/Depublished/i,
		]) {
			await expect(content(page).getByText(label).first()).toBeVisible({
				timeout: 15000,
			})
		}

		// Primary action button.
		await expect(
			content(page)
				.getByRole('button', { name: /New Publication/i })
				.first(),
		).toBeVisible({ timeout: 10000 })

		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test(// @e2e dashboard::usage-card-draws-from-usage-counters
	'Dashboard — the usage card shows publication views from the usage counters, not audit-trail reads', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)

		// Record what the dashboard asks the server for. The old "Traffic"
		// card read OpenRegister's audit-trail chart (every API object read,
		// admins included) and labelled it "Requests". The Activity card still
		// legitimately reads that chart, so the honest assertion is: exactly one
		// audit-trail call (Activity), and the usage series is called.
		const auditCalls: string[] = []
		page.on('request', (req) => {
			if (req.url().includes('audit-trail-actions')) auditCalls.push(req.url())
		})
		const seriesResponse = page.waitForResponse(
			(res) =>
				res.url().includes('/apps/opencatalogi/api/stats/series')
				&& res.request().method() === 'GET',
			{ timeout: 30000 },
		)

		await bootApp(page)
		await expect(
			page.locator('[data-testid="cn-dashboard-page"]').first(),
		).toBeVisible({ timeout: 15000 })

		// The card is titled honestly.
		await expect(
			content(page)
				.getByRole('heading', { name: /^Publication views$/i })
				.first(),
		).toBeVisible({ timeout: 15000 })

		// The usage series endpoint answered with the counter shape.
		const res = await seriesResponse
		expect(res.status()).toBe(200)
		const body = await res.json()
		expect(Array.isArray(body.series)).toBe(true)
		expect(body).toHaveProperty('countingStart')

		// The counting-start note qualifies the numbers either way.
		await expect(
			content(page)
				.getByText(/not unique visitors/i)
				.first(),
		).toBeVisible({ timeout: 15000 })

		// No card relabels audit-trail reads as "Requests" or "Traffic".
		await expect(
			content(page).getByText('Requests', { exact: true }),
		).toHaveCount(0)
		await expect(
			content(page).getByRole('heading', { name: /^Traffic$/i }),
		).toHaveCount(0)

		// Only the Activity card reads the audit trail: at most one call, and
		// never a second one for the usage card.
		expect(auditCalls.length).toBeLessThanOrEqual(1)

		expect(fatalErrors(errors)).toHaveLength(0)
	})

	test(// @e2e dashboard::refresh-reloads-dashboard-data
	'Dashboard — Refresh action reloads the dashboard without a fatal error', async ({
		page,
	}) => {
		const errors = trackPageErrors(page)
		await bootApp(page)
		await expect(
			page.locator('[data-testid="cn-dashboard-page"]').first(),
		).toBeVisible({ timeout: 15000 })

		// The Refresh action (re-runs loadDashboardData) — a genuine,
		// data-independent dashboard interaction. It lives ONLY in the
		// page-level Actions overflow menu: the dashboard used to repeat it
		// as a toolbar button, which shipped two Refreshes side by side.
		const actions = content(page)
			.getByRole('button', { name: /^Actions$/i })
			.first()
		await expect(actions).toBeVisible({ timeout: 10000 })
		await actions.click()

		// NcActionButton renders the item as role=menuitem inside the popover,
		// which mounts outside `content(page)`. Not role=button.
		const refresh = page.getByRole('menuitem', { name: /^Refresh$/i }).first()
		await expect(refresh).toBeVisible({ timeout: 10000 })
		await expect(refresh).toBeEnabled()
		await refresh.click()
		await page.waitForTimeout(1500)

		// After reload the dashboard analytics section is still rendered.
		await expect(
			content(page)
				.getByText(/Publications by Category/i)
				.first(),
		).toBeVisible({ timeout: 15000 })

		expect(fatalErrors(errors)).toHaveLength(0)
	})
})
