/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public side cannot reach a non-public record (REQ-WAB-002, task 2.3).
 *
 *   @e2e woo-compliance::no-public-route-returns-a-non-public-record
 *
 * Over HTTP and anonymously, against the CI seed (`ci-seed.sh` step 2c, which
 * makes one record of every non-public kind under a fixed `Boundary fixture:`
 * title):
 *
 * 1. Every GET route whose controller method carries `#[PublicPage]` is
 *    called with every fixture id in each path parameter (and each catalog's
 *    slug in `{catalogSlug}`). No 2xx body may carry a fixture's id, title or
 *    file name; no other body may carry a title or file name (an error that
 *    repeats the id it was asked for leaks nothing).
 * 2. Every route WITHOUT `#[PublicPage]` answers an anonymous caller with 401
 *    or 403 (or Nextcloud's redirect to its login page).
 *
 * The route list is read from `appinfo/routes.php` and the controllers on
 * every run, so a new public route is covered without anyone adding it here.
 * A public search over POST (`publicationRules#publicSearch`) is called with
 * an empty body like a GET. The other public routes that are not GET (saved
 * searches, collections, CORS preflights) are not called: they take bodies,
 * not ids, and can write.
 *
 * The admin account is used only to find the fixtures and the catalogs; every
 * probe runs in a context with no credentials and an empty storage state.
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-a-test-proves-the-public-side-cannot-reach-a-non-public-record-req-wab-002
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { BASE_URL } from './base-url.ts'

const ROOT = path.resolve(__dirname, '..', '..')
const APP = '/index.php/apps/opencatalogi'
const OBJECTS = '/index.php/apps/openregister/api/objects/publication'


/** The non-public fixtures the seed makes: schema, the field holding the marker, the marker. */
const FIXTURES: Array<{ schema: string, field: string, marker: string }> = [
	{ schema: 'publication', field: 'title', marker: 'Boundary fixture: draft publication' },
	{ schema: 'publication', field: 'title', marker: 'Boundary fixture: scheduled publication' },
	{ schema: 'publication', field: 'title', marker: 'Boundary fixture: withdrawn publication' },
	{ schema: 'publication', field: 'title', marker: 'Boundary fixture: archived publication' },
	{ schema: 'wooAssessment', field: 'documentReference', marker: 'Boundary fixture: woo assessment' },
	{ schema: 'wooBatch', field: 'caseReference', marker: 'Boundary fixture: woo batch' },
	{ schema: 'wooRequest', field: 'reference', marker: 'Boundary fixture: woo request' },
	{ schema: 'depublication', field: 'reason', marker: 'Boundary fixture: depublication' },
]
const WITHDRAWN_FILE = 'boundary-fixture-withdrawn-file.txt'
const PUBLIC_WITH_FILE = 'Boundary fixture: public publication with a withdrawn file'

interface Route {
	name: string
	url: string
	verb: string
	isPublic: boolean
}

/**
 * Whether a controller method carries `#[PublicPage]` (or the `@PublicPage` annotation).
 *
 * @param controller The route's controller part, e.g. `catalogi`.
 * @param method The method.
 */
function isPublicMethod(controller: string, method: string): boolean {
	if (controller.includes('\\')) {
		// AppHost controllers served from OpenRegister; none of them is public.
		return false
	}
	const file = path.join(ROOT, 'lib', 'Controller', `${controller[0].toUpperCase()}${controller.slice(1)}Controller.php`)
	const source = fs.readFileSync(file, 'utf8')
	const at = source.search(new RegExp(`public\\s+function\\s+${method}\\s*\\(`))
	if (at < 0) {
		throw new Error(`${controller}#${method}: no such method in ${file}`)
	}
	// The attributes and docblock between the previous member and this one.
	const before = source.slice(0, at)
	const start = Math.max(before.lastIndexOf('}'), before.lastIndexOf(';'))
	return /#\[PublicPage\]|@PublicPage\b/.test(before.slice(start))
}

/**
 * Every route in `appinfo/routes.php`, with whether it is public.
 */
function routeTable(): Route[] {
	const source = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
	const names = [...source.matchAll(/'name'\s*=>\s*'([^']+)'/g)]
	return names.map((match, index) => {
		const end = index + 1 < names.length ? names[index + 1].index : source.length
		const entry = source.slice(match.index, end)
		const url = /'url'\s*=>\s*'([^']+)'/.exec(entry)?.[1]
		const verb = /'verb'\s*=>\s*'([^']+)'/.exec(entry)?.[1] ?? 'GET'
		if (url === undefined) {
			throw new Error(`route ${match[1]} has no url`)
		}
		const [controller, method] = match[1].split('#')
		return { name: match[1], url, verb: verb.toUpperCase(), isPublic: isPublicMethod(controller, method) }
	})
}

/**
 * The route's URL with each placeholder filled.
 *
 * @param url The route's url.
 * @param fill A value per placeholder name.
 */
function filled(url: string, fill: (name: string) => string): string {
	return APP + url.replace(/\{([A-Za-z]+)\}/g, (_all, name: string) => encodeURIComponent(fill(name)))
}

/**
 * The ids of a schema's objects whose field equals the marker, read as admin.
 *
 * @param admin The admin context.
 * @param schema The schema slug.
 * @param field The field.
 * @param marker The value.
 */
async function idsOf(admin: APIRequestContext, schema: string, field: string, marker: string): Promise<string[]> {
	const response = await admin.get(`${OBJECTS}/${schema}`, { params: { [field]: marker, _limit: '20' } })
	expect(response.ok(), `listing ${schema} as admin: ${response.status()}`).toBeTruthy()
	const body = await response.json()
	const rows: any[] = Array.isArray(body) ? body : (body.results ?? [])
	return rows.filter((row) => row?.[field] === marker).map((row) => String(row.id ?? row['@self']?.id ?? row.uuid))
}

/**
 * Whether the suite calls a route anonymously with the fixture ids: a public
 * GET, or a public search over POST.
 *
 * @param route The route.
 */
function readsPublicly(route: Route): boolean {
	return route.isPublic && (route.verb === 'GET' || (route.verb === 'POST' && /#.*search/i.test(route.name)))
}

test.use({ storageState: { cookies: [], origins: [] } })

test.describe('the public boundary', () => {
	const routes = routeTable()
	let ids: string[] = []
	let catalogSlugs: string[] = []

	test.beforeAll(async () => {
		const admin = await request.newContext({
			baseURL: BASE_URL,
			httpCredentials: {
				username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
				password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
			},
			extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		for (const fixture of FIXTURES) {
			const found = await idsOf(admin, fixture.schema, fixture.field, fixture.marker)
			expect(found.length, `the CI seed must hold "${fixture.marker}" (ci-seed.sh step 2c)`).toBeGreaterThan(0)
			ids.push(...found)
		}
		expect(await idsOf(admin, 'publication', 'title', PUBLIC_WITH_FILE), 'the public publication carrying the withdrawn file').toHaveLength(1)
		const catalogs = await admin.get(`${OBJECTS}/catalog`, { params: { _limit: '100' } })
		const catalogRows: any[] = ((await catalogs.json()).results ?? [])
		catalogSlugs = catalogRows.map((row) => String(row.slug ?? '')).filter((slug) => slug !== '')
		await admin.dispose()
		ids = [...new Set(ids)]
	})

	test('the route list is read from the route table', () => {
		expect(routes.filter(readsPublicly).length, 'public read routes found in appinfo/routes.php').toBeGreaterThan(0)
		expect(routes.some((route) => !route.isPublic), 'non-public routes found in appinfo/routes.php').toBeTruthy()
	})

	test('no public route returns a non-public record', async () => {
		test.setTimeout(600_000)
		const anonymous = await request.newContext({ baseURL: BASE_URL, extraHTTPHeaders: { Accept: 'application/json' } })
		const titles = [...FIXTURES.map((fixture) => fixture.marker), WITHDRAWN_FILE]
		const leaks: string[] = []
		for (const route of routes.filter(readsPublicly)) {
			const placeholders = [...route.url.matchAll(/\{([A-Za-z]+)\}/g)].map((m) => m[1])
			const urls = new Set<string>()
			if (placeholders.length === 0) {
				urls.add(filled(route.url, () => ''))
			}
			for (const id of placeholders.length === 0 ? [] : ids) {
				const slugs = placeholders.includes('catalogSlug') && catalogSlugs.length > 0 ? catalogSlugs : [id]
				for (const slug of slugs) {
					urls.add(filled(route.url, (name) => (name === 'catalogSlug' ? slug : id)))
				}
			}
			for (const url of urls) {
				const response = await anonymous.fetch(url, {
					method: route.verb,
					data: route.verb === 'POST' ? {} : undefined,
					maxRedirects: 0,
					failOnStatusCode: false,
				})
				const body = await response.text()
				const forbidden = response.status() >= 200 && response.status() < 300 ? [...ids, ...titles] : titles
				for (const value of forbidden) {
					if (body.includes(value)) {
						leaks.push(`${route.name} GET ${url} -> ${response.status()} carries "${value}"`)
					}
				}
			}
		}
		await anonymous.dispose()
		expect(leaks, leaks.join('\n')).toEqual([])
	})

	test('every non-public route refuses an anonymous caller', async () => {
		test.setTimeout(600_000)
		const anonymous = await request.newContext({
			baseURL: BASE_URL,
			extraHTTPHeaders: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
		})
		const open: string[] = []
		for (const route of routes.filter((r) => !r.isPublic)) {
			const url = filled(route.url, () => 'boundary-probe')
			const response = await anonymous.fetch(url, { method: route.verb, maxRedirects: 0, failOnStatusCode: false })
			const status = response.status()
			const toLogin = status >= 300 && status < 400 && (response.headers().location ?? '').includes('/login')
			if (status !== 401 && status !== 403 && !toLogin) {
				open.push(`${route.name} ${route.verb} ${url} -> ${status}`)
			}
		}
		await anonymous.dispose()
		expect(open, open.join('\n')).toEqual([])
	})
})
