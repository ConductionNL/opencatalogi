/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every officer page passes axe at WCAG 2.2 AA (officer-flow-accessibility, REQ-OFA-001).
 *
 * The page list is read from src/manifest.json when the suite loads, so a new
 * page is covered by default. A page with route parameters is opened on an
 * object the instance holds (the CI seed), found through OpenRegister's
 * objects API. Each page runs axe-core (the app's own dependency, injected
 * from node_modules) with the tags wcag2a, wcag2aa, wcag21a, wcag21aa and
 * wcag22aa, scoped to the app's content root. A serious or critical violation
 * that is not in tests/e2e/a11y-allowlist.json fails the page; an allowlist
 * entry that no longer occurs fails the run, and so does a page that does
 * not load or has no object to open.
 *
 * @e2e openspec/changes/officer-flow-accessibility/specs/officer-accessibility/spec.md#requirement-every-officer-page-passes-axe-at-wcag-22-aa-req-ofa-001 REQ-OFA-001
 */
import type { APIRequestContext, Page } from '@playwright/test'

import * as fs from 'fs'
import * as path from 'path'
import { expect, test } from './authenticated-request.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

type ManifestPage = { id: string; route: string; type?: string }
type AllowEntry = { rule: string; page: string; reason: string; issue: string }
type Finding = { rule: string; impact: string; nodes: number; help: string }

const ROOT = path.resolve(__dirname, '..', '..')
const MANIFEST: { pages: ManifestPage[] } = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const ALLOWLIST: AllowEntry[] = JSON.parse(fs.readFileSync(path.join(__dirname, 'a11y-allowlist.json'), 'utf8')).entries ?? []
const AXE_SOURCE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8')
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']
const OBJECTS = '/index.php/apps/openregister/api/objects/publication'

/** The first object of a schema in the publication register, with its register and schema ids. */
async function first(request: APIRequestContext, schema: string): Promise<Record<string, any> | null> {
	const res = await request.get(`${OBJECTS}/${schema}?_limit=1`)
	if (!res.ok()) return null
	const row = ((await res.json()).results ?? [])[0]
	return row ?? null
}

/** The concrete path of a manifest route, its parameters filled from the instance; null when it holds no object. */
async function concretePath(request: APIRequestContext, page: ManifestPage): Promise<string | null> {
	if (!page.route.includes(':')) return page.route
	const values: Record<string, string> = {}
	const catalog = await first(request, 'catalog')
	if (catalog) values.catalogSlug = String(catalog.slug ?? '')
	switch (page.id) {
	case 'CatalogDetail':
		values.id = String(catalog?.id ?? '')
		break
	case 'PublicationDetail':
	case 'CatalogObjectDetail': {
		const publication = await first(request, 'publication')
		values.id = String(publication?.id ?? '')
		values.register = String(publication?.['@self']?.register ?? '')
		values.schema = String(publication?.['@self']?.schema ?? '')
		break
	}
	case 'ThemeDetail':
		values.id = String((await first(request, 'theme'))?.id ?? '')
		break
	case 'GlossaryDetail':
		values.id = String((await first(request, 'glossary'))?.id ?? '')
		break
	case 'OrganizationDetail': {
		const res = await request.get('/index.php/apps/openregister/api/organisations')
		values.id = res.ok() ? String((await res.json()).active?.uuid ?? '') : ''
		break
	}
	case 'FlowDetail': {
		const res = await request.get('/index.php/apps/openregister/api/flows?_limit=1')
		values.id = res.ok() ? String(((await res.json()).results ?? [])[0]?.id ?? '') : ''
		break
	}
	default:
		break
	}
	let filled = page.route
	for (const name of page.route.match(/:([A-Za-z]+)/g) ?? []) {
		const value = values[name.slice(1)] ?? ''
		if (value === '') return null
		filled = filled.replace(name, encodeURIComponent(value))
	}
	return filled
}

/** Run axe on the app's content root; answer the serious and critical violations. */
async function audit(page: Page): Promise<Finding[]> {
	await page.addScriptTag({ content: AXE_SOURCE })
	return page.evaluate(async (tags: string[]) => {
		const root = document.querySelector('#content-vue') ?? document.querySelector('#content') ?? document.body
		// eslint-disable-next-line @typescript-eslint/no-explicit-any
		const result = await (window as any).axe.run(root, { runOnly: { type: 'tag', values: tags } })
		return result.violations
			.filter((violation: { impact: string }) => violation.impact === 'serious' || violation.impact === 'critical')
			.map((violation: { id: string; impact: string; nodes: unknown[]; help: string }) => ({
				rule: violation.id,
				impact: violation.impact,
				nodes: violation.nodes.length,
				help: violation.help,
			}))
	}, TAGS)
}

test.describe.serial('Officer pages pass axe (REQ-OFA-001)', () => {
	test.setTimeout(90_000)
	const checked: string[] = []
	const seen = new Set<string>()

	for (const manifestPage of MANIFEST.pages) {
		test(`${manifestPage.id} (${manifestPage.route})`, async ({ page, request }, testInfo) => {
			const route = await concretePath(request, manifestPage)
			expect(route, `no object on this instance to open ${manifestPage.route}`).not.toBeNull()

			const response = await page.goto(`${APP}${route}`, { waitUntil: 'domcontentloaded' })
			expect(response?.status() ?? 0, 'the page loads').toBeLessThan(400)
			await dismissOverlays(page)
			await expect(page.locator('#content-vue, #content').first()).toBeVisible({ timeout: 30_000 })
			await page.waitForLoadState('networkidle').catch(() => undefined)
			checked.push(manifestPage.id)

			const findings = await audit(page)
			await testInfo.attach('axe', { body: JSON.stringify(findings, null, 2), contentType: 'application/json' })
			const outside = findings.filter((finding) => {
				const allowed = ALLOWLIST.some((entry) => entry.page === manifestPage.id && entry.rule === finding.rule)
				if (allowed) seen.add(`${manifestPage.id}:${finding.rule}`)
				return !allowed
			})
			expect(outside, `serious or critical violations on ${manifestPage.id}`).toEqual([])
		})
	}

	test('every manifest page was checked and every allowlist entry still occurs', () => {
		expect(checked.length, 'pages checked').toBe(MANIFEST.pages.length)
		const stale = ALLOWLIST.filter((entry) => !seen.has(`${entry.page}:${entry.rule}`))
		expect(stale, 'allowlist entries that no longer occur').toEqual([])
	})
})
