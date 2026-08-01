/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Behavioral UI coverage for publication-usage-analytics (ANA-006): the
 * per-publication usage stats surface on the publication detail page, and
 * the "Most viewed publications" Nextcloud dashboard widget.
 *
 * HONESTY NOTE (repaired suite): the previous version navigated via
 * `navTo(page, 'PublicationsMenu').catch(() => {})` — but no nav entry with
 * menuId "PublicationsMenu" exists in src/manifest.json (publications are
 * reached via /publications/:catalogSlug, from a catalog). The catch
 * swallowed the missing entry and every subsequent assertion was if-guarded,
 * so the test asserted nothing. This version seeds/locates a catalog and a
 * publication via the API, navigates the real hash route to the publication
 * detail page, and asserts the analytics surface UNCONDITIONALLY. The
 * most-viewed widget is an NC *dashboard* widget
 * (opencatalogi_most_viewed_publications_widget), so it is asserted on
 * /apps/dashboard after enabling it via the dashboard layout API — not on
 * the in-app CnDashboardPage where it never renders.
 *
 * API/aggregation assertions live in Newman + PHPUnit (Playwright is UI-only).
 *
 * Run:
 *   NEXTCLOUD_URL=http://localhost:8080 npx playwright test usage-analytics-page
 */
import { test, expect, request as pwRequest, type APIRequestContext } from '@playwright/test'
import { APP, bootApp, dismissOverlays, trackPageErrors, fatalErrors } from './_nav'
import { resolveBaseUrl } from '../base-url'

const RUN_ID = `ana-${Date.now()}`
const WIDGET_ID = 'opencatalogi_most_viewed_publications_widget'
// No hardcoded fallback — see tests/e2e/base-url.ts.
const BASE_URL = resolveBaseUrl()
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

/**
 * Read the app's configured register/schema ids for one object type.
 * Ids live under the settings payload's `configuration` key; when unset
 * (e.g. `publication` is not part of the settings objectTypes list) we fall
 * back to the OpenRegister slug path, which the OR objects API accepts.
 *
 * @param request The Playwright API request context (authenticated session).
 * @param type The OpenCatalogi object type to resolve.
 */
async function objectTypeConfig(
	request: APIRequestContext,
	type: 'catalog' | 'publication',
): Promise<{ register: string, schema: string }> {
	const resp = await request.get('/index.php/apps/opencatalogi/api/settings')
	expect(resp.status(), 'GET /api/settings must succeed').toBe(200)
	const settings = await resp.json()
	const register = settings?.configuration?.[`${type}_register`]
		?? settings?.[`${type}_register`] ?? 'publication'
	const schema = settings?.configuration?.[`${type}_schema`]
		?? settings?.[`${type}_schema`] ?? type
	return { register: String(register), schema: String(schema) }
}

/**
 * Resolve an existing catalog slug, seeding a catalog when none exists.
 *
 * @param request The Playwright API request context (authenticated session).
 */
async function resolveCatalogSlug(request: APIRequestContext): Promise<string> {
	const list = await request.get('/index.php/apps/opencatalogi/api/catalogi')
	expect(list.status(), 'GET /api/catalogi must succeed').toBe(200)
	const body = await list.json()
	const results: Array<Record<string, any>> = Array.isArray(body) ? body : (body?.results ?? [])
	const existing = results.find((c) => c?.slug || c?.['@self']?.slug)
	if (existing) return String(existing.slug ?? existing['@self']?.slug)

	const { register, schema } = await objectTypeConfig(request, 'catalog')
	const slug = `${RUN_ID}-cat`
	const created = await request.post(
		`/index.php/apps/openregister/api/objects/${register}/${schema}`,
		{
			data: { title: `${RUN_ID} catalog`, summary: 'usage-analytics seeded catalog', slug, listed: true },
			headers: { 'Content-Type': 'application/json' },
		},
	)
	expect(created.status(), 'seeding a catalog must succeed').toBeLessThan(300)
	return slug
}

/**
 * Resolve an existing publication id, seeding one when none exists.
 *
 * @param request The Playwright API request context (authenticated session).
 */
async function resolvePublicationId(request: APIRequestContext): Promise<string> {
	const { register, schema } = await objectTypeConfig(request, 'publication')
	const list = await request.get(
		`/index.php/apps/openregister/api/objects/${register}/${schema}?_limit=1`,
	)
	expect(list.status(), 'listing publications via OpenRegister must succeed').toBe(200)
	const body = await list.json()
	const results: Array<Record<string, any>> = Array.isArray(body) ? body : (body?.results ?? [])
	const existing = results[0]
	if (existing) {
		return String(existing['@self']?.id ?? existing.id ?? existing.uuid)
	}
	const created = await request.post(
		`/index.php/apps/openregister/api/objects/${register}/${schema}`,
		{
			data: {
				title: `${RUN_ID} publication`,
				summary: 'usage-analytics seeded publication',
				description: 'Seeded by usage-analytics-page.spec.ts',
				status: 'concept',
			},
			headers: { 'Content-Type': 'application/json' },
		},
	)
	expect(created.status(), 'seeding a publication must succeed').toBeLessThan(300)
	const obj = await created.json()
	return String(obj?.['@self']?.id ?? obj?.id ?? obj?.uuid)
}

test.describe('usage-analytics', () => {
	test(
		// @e2e publication-usage-analytics::stats-panel-on-the-detail-page
		'Stats panel — the publication detail page renders the Views/Downloads usage stats surface',
		async ({ page, request }) => {
			const errors = trackPageErrors(page)

			const slug = await resolveCatalogSlug(request)
			const pubId = await resolvePublicationId(request)

			await bootApp(page)

			// The Publications index for the catalog (hash route — path-form
			// gotos boot the Dashboard in this hash-mode SPA).
			await page.goto(`${APP}/#/publications/${slug}`, { waitUntil: 'domcontentloaded' })
			await page.waitForTimeout(1500)
			await dismissOverlays(page)
			await expect(page.locator('[data-testid="cn-index-page"]').first())
				.toBeVisible({ timeout: 15000 })

			// The publication detail page for a real publication.
			await page.goto(`${APP}/#/publications/${slug}/${pubId}`, { waitUntil: 'domcontentloaded' })
			await page.waitForTimeout(1500)
			await dismissOverlays(page)
			await expect(page.locator('[data-testid="cn-detail-page"]').first())
				.toBeVisible({ timeout: 15000 })

			// The usage stats surface (manifest stats-block widgets pub-stats-views
			// and pub-stats-downloads) MUST render its Views and Downloads totals —
			// unconditionally. Zero-usage publications still render the blocks.
			const detail = page.locator('[data-testid="cn-detail-page"]').first()
			await expect(detail.getByText(/^Views$/i).first()).toBeVisible({ timeout: 15000 })
			await expect(detail.getByText(/^Downloads$/i).first()).toBeVisible({ timeout: 15000 })

			expect(fatalErrors(errors)).toHaveLength(0)
		},
	)

	/**
	 * REAL DEFECT (exposed by de-vacuuming this suite): ANA-006 requires the
	 * detail-page stats panel to show "totals + a recent trend" and the
	 * counting-start note. Those live in
	 * src/views/publications/PublicationStatsPanel.vue
	 * (data-testids usage-stats-panel / usage-counting-start, hosted by the
	 * bespoke PublicationDetail.vue "Statistics" BTab) — but NO route mounts
	 * that component: the manifest PublicationDetail page is rendered by
	 * CnDetailPage from src/manifest.json, whose stats-block widgets show
	 * Views/Downloads TOTALS only. The trend + counting-start surface is
	 * orphaned dead code, so this honest assertion cannot pass until the
	 * panel is wired into the manifest detail page (or the manifest grows a
	 * trend widget). Do not weaken; un-fixme when the surface ships.
	 */
	test.fixme(
		// @e2e publication-usage-analytics::stats-panel-on-the-detail-page
		'Stats panel — trend + counting-start note render on the detail page (ANA-006, orphaned PublicationStatsPanel)',
		async ({ page, request }) => {
			const slug = await resolveCatalogSlug(request)
			const pubId = await resolvePublicationId(request)
			await bootApp(page)
			await page.goto(`${APP}/#/publications/${slug}/${pubId}`, { waitUntil: 'domcontentloaded' })
			await page.waitForTimeout(1500)
			await expect(page.locator('[data-testid="usage-stats-panel"]').first())
				.toBeVisible({ timeout: 15000 })
			await expect(page.locator('[data-testid="usage-counting-start"]').first())
				.toBeVisible({ timeout: 10000 })
		},
	)

	test(
		// @e2e publication-usage-analytics::most-viewed-dashboard-widget
		'Most-viewed widget — enabling the NC dashboard widget renders it with content',
		async ({ page }) => {
			const errors = trackPageErrors(page)

			// The dashboard layout OCS API needs non-cookie auth (cookie-auth OCS
			// calls fail the CSRF check), so use a basic-auth request context.
			const ocs = await pwRequest.newContext({
				baseURL: BASE_URL,
				httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
				extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			})
			try {
				const layoutResp = await ocs.get('/ocs/v2.php/apps/dashboard/api/v3/layout?format=json')
				expect(layoutResp.status(), 'GET dashboard layout must succeed').toBe(200)
				const layoutBody = await layoutResp.json()
				const originalLayout: string[] = layoutBody?.ocs?.data?.layout ?? []

				try {
					// Enable the most-viewed widget when it is not already on the board.
					if (!originalLayout.includes(WIDGET_ID)) {
						const setResp = await ocs.post('/ocs/v2.php/apps/dashboard/api/v3/layout?format=json', {
							headers: { 'Content-Type': 'application/json' },
							data: { layout: [...originalLayout, WIDGET_ID] },
						})
						expect(setResp.status(), 'POST dashboard layout must succeed').toBe(200)
					}

					// The NC dashboard must render the widget panel and the widget's
					// own root element (mounted by src/mostViewedPublicationsWidget.js).
					await page.goto('/index.php/apps/dashboard/', { waitUntil: 'domcontentloaded' })
					await dismissOverlays(page)
					await expect(page.locator('#app-dashboard, .app-dashboard').first())
						.toBeVisible({ timeout: 20000 })
					await expect(page.locator(`[data-testid="most-viewed-widget"], #${WIDGET_ID}`).first())
						.toBeVisible({ timeout: 20000 })
					// The widget panel carries its registered title.
					await expect(page.getByText(/Most viewed publications/i).first())
						.toBeVisible({ timeout: 15000 })
				} finally {
					// Restore the user's original layout so the shared dev instance
					// is left as found.
					await ocs.post('/ocs/v2.php/apps/dashboard/api/v3/layout?format=json', {
						headers: { 'Content-Type': 'application/json' },
						data: { layout: originalLayout },
					}).catch(() => {})
				}
			} finally {
				await ocs.dispose()
			}

			// The NC dashboard hosts widgets from OTHER apps too; the Photos
			// app's greeting background throws "Couldn't fetch photos upload
			// folder" on this dev instance. That is not an OpenCatalogi error —
			// exclude it, but keep failing on anything else.
			const relevant = fatalErrors(errors).filter((e) => !/photos upload folder/i.test(e))
			expect(relevant).toHaveLength(0)
		},
	)
})
