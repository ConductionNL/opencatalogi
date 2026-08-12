/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Repository-invariant spec coverage for OpenCatalogi.
 *
 * WHY THESE LIVE IN THE PLAYWRIGHT SUITE
 * -------------------------------------
 * A handful of OpenCatalogi's specified scenarios are not browser-observable
 * by construction. They are written as repository invariants — their own
 * wording is "WHEN the file is inspected", "WHEN the licence declaration is
 * compared with ...", "WHEN the register JSON is inspected". The subject is a
 * file on disk, not a rendered surface, so driving a browser at them would
 * assert nothing about what they actually require.
 *
 * The two alternatives were both worse than this file:
 *
 *   1. Mark them excluded. An exclusion is scored as POSITIVE coverage by the
 *      e2e-coverage gate, and on this repo a single exclusion reason already
 *      speaks for a median of ~10 sibling scenarios. That buys a green with a
 *      statement nobody checks. It is the thing this suite exists to prevent.
 *
 *   2. Leave them uncovered forever. The invariants are real and regressions
 *      in them are exactly the kind CI should catch — the licence quartet has
 *      drifted before, and writing this file turned up a live violation of the
 *      lodash rule that had been shipping under a spec marked "Implemented".
 *
 * So each test below asserts the invariant directly, from disk, with no
 * browser fixture (no `page` argument — Playwright launches no browser for
 * these). They execute in the same run as the UI specs and fail the same way.
 *
 * WHAT THESE TESTS ARE NOT
 * ------------------------
 * They are not a substitute for the UI specs, and they deliberately do NOT
 * cover the scenarios that genuinely need a rendered page (theme-following
 * charts, dark-mode contrast, runtime notification dispatch) or a produced
 * bundle. Those stay uncovered and visible rather than being papered over
 * here. See findings/opencatalogi.md for the per-scenario triage.
 *
 * Run:
 *   npx playwright test repository-invariants
 */

import { test, expect } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

/** Repository root — this file sits at <root>/tests/e2e/spec-coverage/. */
const ROOT = path.resolve(__dirname, '..', '..', '..')

/**
 * Read a repository file as UTF-8, failing loudly when it is absent.
 *
 * A missing file must fail the invariant rather than yield an empty string:
 * an empty haystack makes every "MUST NOT contain" assertion pass for free,
 * which is precisely how a check that never ran comes to look like one that
 * passed.
 *
 * @param rel Repository-relative path.
 * @return The file's contents.
 */
function read(rel: string): string {
	const abs = path.join(ROOT, rel)
	expect(fs.existsSync(abs), `${rel} must exist in the repository`).toBe(true)
	const text = fs.readFileSync(abs, 'utf8')
	expect(text.length, `${rel} must not be empty`).toBeGreaterThan(0)
	return text
}

/**
 * The schema map of the publication register, as OpenRegister reads it.
 *
 * @return Schema name → schema definition.
 */
function registerSchemas(): Record<string, any> {
	const doc = JSON.parse(read('lib/Settings/publication_register.json'))
	const schemas = doc?.components?.schemas ?? doc?.schemas
	expect(schemas, 'publication_register.json must declare schemas').toBeTruthy()
	// Guard the lookup itself: an empty map would satisfy every "no key on
	// these schemas" assertion below without inspecting anything.
	expect(Object.keys(schemas).length, 'schema map must be non-empty').toBeGreaterThan(5)
	return schemas
}

// ── app-packaging ────────────────────────────────────────────────────────────

test.describe('app-packaging (repository invariants)', () => {
	test(
		// @e2e app-packaging::licence-is-consistent-across-all-metadata-files
		'PKG-001 — info.xml, LICENSE, composer.json and publiccode.yml all declare EUPL-1.2',
		async () => {
			const infoXml = read('appinfo/info.xml')
			const licenceTag = infoXml.match(/<licence>([^<]*)<\/licence>/)
			expect(licenceTag, 'info.xml must carry a <licence> element').not.toBeNull()
			expect(licenceTag![1].trim()).toBe('EUPL-1.2')
			// The specific regression PKG-001 names.
			expect(licenceTag![1].trim().toLowerCase()).not.toBe('agpl')

			expect(JSON.parse(read('composer.json')).license).toBe('EUPL-1.2')

			// publiccode.yml nests the key under `legal:`; match the key itself
			// rather than a line prefix so indentation changes do not fake a pass.
			expect(read('publiccode.yml')).toMatch(/^\s*license:\s*EUPL-1\.2\s*$/m)

			expect(read('LICENSE')).toContain('EUROPEAN UNION PUBLIC LICENCE v. 1.2')
		},
	)

	test(
		// @e2e app-packaging::no-elasticsearch-claim
		'PKG-003 — the README claims no ElasticSearch backend and names OpenRegister SOLR',
		async () => {
			const readme = read('README.md')
			expect(
				readme,
				'the README must not claim an ElasticSearch backend — none exists in lib/',
			).not.toMatch(/elasticsearch/i)
			// The positive half: the real optional backend must be named. Without
			// this, deleting every mention of search would also pass.
			expect(readme).toMatch(/OpenRegister\s+SOLR/i)
		},
	)

	test(
		// @e2e app-packaging::document-content-search-is-marked-planned
		'PKG-003 — document-content search is described as planned, not as shipped',
		async () => {
			const readme = read('README.md')
			const line = readme
				.split('\n')
				.find((l) => /attached document content/i.test(l))
			expect(
				line,
				'the README must describe searching across attached document content',
			).toBeTruthy()
			expect(line!).toMatch(/\bplanned\b/i)
			expect(line!).toContain('add-public-fulltext-search')
		},
	)

	test(
		// @e2e app-packaging::documented-path-is-reachable
		'PKG-004 — the aggregated-publications doc names the routed endpoint and entry point',
		async () => {
			const doc = read('README_AGGREGATED_PUBLICATIONS.md')
			const routes = read('appinfo/routes.php')

			expect(doc).toContain('/api/federation/publications')
			expect(
				doc,
				'the unrouted /api/publications/aggregated path must not be documented',
			).not.toContain('/api/publications/aggregated')

			expect(doc).toContain('PublicationService::getAggregatedPublications')
			expect(
				doc,
				'DirectoryService::getPublications is not the entry point',
			).not.toContain('DirectoryService::getPublications')
			expect(doc, 'the doc must cross-reference the federation capability').toContain('FED-001')

			// The documented path must actually be registered, which is the whole
			// point of the requirement — the doc and the router must agree.
			expect(routes).toMatch(/'url'\s*=>\s*'\/api\/federation\/publications'/)
			expect(routes).not.toMatch(/'url'\s*=>\s*'\/api\/publications\/aggregated'/)
		},
	)
})

// ── frontend-performance ─────────────────────────────────────────────────────

/** Files FEP-001 names as single-`cloneDeep` call sites. */
const SINGLE_CLONE_FILES = [
	'src/modals/object/ObjectModal.vue',
	'src/modals/menu/ViewMenuModal.vue',
	'src/modals/menuItem/MenuItemForm.vue',
	'src/dialogs/page/DeletePageContentDialog.vue',
]

test.describe('frontend-performance (repository invariants)', () => {
	test(
		// @e2e frontend-performance::single-use-clonedeep-call-sites-use-structuredclone-not-lodash
		'FEP-001 — single-clone call sites use structuredClone and import no lodash',
		async () => {
			for (const rel of SINGLE_CLONE_FILES) {
				const src = read(rel)
				// "MUST NOT import lodash at all" — barrel or per-function path.
				expect(src, `${rel} must not import lodash in any form`).not.toMatch(
					/\bfrom\s+['"]lodash(\/[\w-]+)?['"]/,
				)
				expect(src, `${rel} must not require lodash`).not.toMatch(
					/\brequire\(\s*['"]lodash(\/[\w-]+)?['"]\s*\)/,
				)
				// And the clone must actually still happen, natively.
				expect(src, `${rel} must clone via structuredClone`).toContain('structuredClone(')
			}
		},
	)

	test(
		// @e2e frontend-performance::multi-function-call-site-cherry-picks-named-lodash-modules
		'FEP-001 — the multi-function call site cherry-picks named lodash modules',
		async () => {
			const rel = 'src/modals/pageContents/PageContentForm.vue'
			const src = read(rel)
			expect(src).toMatch(/import\s+cloneDeep\s+from\s+['"]lodash\/cloneDeep['"]/)
			expect(src).toMatch(/import\s+upperFirst\s+from\s+['"]lodash\/upperFirst['"]/)
			// The barrel specifically — `from 'lodash'` with no sub-path.
			expect(src, `${rel} must not import the lodash barrel`).not.toMatch(
				/\bfrom\s+['"]lodash['"]/,
			)
		},
	)
})

// ── notifications ────────────────────────────────────────────────────────────

/** Schemas that carry no lifecycle or owner field to notify against. */
const OWNERLESS_CONFIG_SCHEMAS = ['page', 'menu', 'theme', 'glossary', 'organization']

test.describe('notifications (repository invariants)', () => {
	test(
		// @e2e notifications::no-notifications-on-ownerless-config-schemas
		'NTF — the ownerless CMS-config schemas declare no notification rules',
		async () => {
			const schemas = registerSchemas()
			for (const name of OWNERLESS_CONFIG_SCHEMAS) {
				expect(schemas[name], `the ${name} schema must exist`).toBeTruthy()
				expect(
					Object.prototype.hasOwnProperty.call(schemas[name], 'x-openregister-notifications'),
					`${name} has no lifecycle or owner field and must declare no notifications`,
				).toBe(false)
			}
			// Control on the assertion itself: the schemas that SHOULD carry the
			// key must carry it, otherwise the loop above would pass over a
			// register in which nothing declares notifications at all.
			for (const name of ['catalog', 'listing', 'publication']) {
				expect(
					Object.prototype.hasOwnProperty.call(schemas[name], 'x-openregister-notifications'),
					`${name} must declare notifications`,
				).toBe(true)
			}
		},
	)

	test(
		// @e2e notifications::publication-carries-its-retention-notifications
		'NTF — the publication retention rules are the declarative dialect, with no imperative dispatch',
		async () => {
			const rules = registerSchemas().publication['x-openregister-notifications']
			expect(rules, 'publication must carry its retention notification rules').toBeTruthy()
			for (const key of ['retention-expiring-soon', 'retention-review-required']) {
				const rule = rules[key]
				expect(rule, `${key} rule must be declared`).toBeTruthy()
				// ADR-031 declarative dialect: a trigger, a channel, recipients and
				// bilingual subjects — data, not code.
				expect(rule.trigger?.type, `${key} must declare a trigger type`).toBeTruthy()
				expect(rule.channels).toContain('nc-notification')
				expect(Array.isArray(rule.recipients) && rule.recipients.length > 0).toBe(true)
				expect(rule.subject?.nl, `${key} must carry a Dutch subject`).toBeTruthy()
				expect(rule.subject?.en, `${key} must carry an English subject`).toBeTruthy()
			}

			// "no imperative notification code in lib/" — the other half of the
			// requirement, and the half a JSON-only check would silently skip.
			const offenders: string[] = []
			const walk = (dir: string): void => {
				for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
					const p = path.join(dir, entry.name)
					if (entry.isDirectory()) {
						walk(p)
					} else if (entry.name.endsWith('.php')) {
						const body = fs.readFileSync(p, 'utf8')
						if (/OCP\\Notification|\bINotification\b|->createNotification\(/.test(body)) {
							offenders.push(path.relative(ROOT, p))
						}
					}
				}
			}
			walk(path.join(ROOT, 'lib'))
			expect(offenders, 'notifications must stay declarative — no dispatch code in lib/').toEqual([])
		},
	)
})

// ── ooapi-catalog-publication ────────────────────────────────────────────────

test.describe('ooapi-catalog-publication (repository invariants)', () => {
	test(
		// @e2e ooapi-catalog-publication::opencatalogi-contains-no-raw-or-storage-sql-for-ooapi-resources
		'OOAPI — the OOAPI code reads through OpenRegister, never raw SQL or its own storage',
		async () => {
			const dirs = ['lib/Service', 'lib/Controller']
			const ooapiFiles: string[] = []
			for (const d of dirs) {
				const abs = path.join(ROOT, d)
				if (!fs.existsSync(abs)) continue
				for (const f of fs.readdirSync(abs)) {
					if (/^Ooapi.*\.php$/.test(f)) ooapiFiles.push(path.join(d, f))
				}
			}
			// Without this the loop below would pass over an empty list — the
			// absence-claim failure mode. OOAPI ships a controller and two services.
			expect(ooapiFiles.length, 'the OOAPI source files must be found').toBeGreaterThanOrEqual(3)

			const forbidden = [
				/\bSELECT\s+/i,
				/\bINSERT\s+INTO\b/i,
				/\bUPDATE\s+\w+\s+SET\b/i,
				/\bDELETE\s+FROM\b/i,
				/IDBConnection/,
				/createQueryBuilder/,
				/IQueryBuilder/,
				/\bQBMapper\b/,
				/executeQuery|executeStatement/,
			]
			for (const rel of ooapiFiles) {
				const src = read(rel)
				for (const rx of forbidden) {
					expect(src, `${rel} must not use ${rx} — OOAPI reads through OpenRegister`)
						.not.toMatch(rx)
				}
			}
		},
	)
})
