/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ONE place the e2e suite learns which Nextcloud to talk to.
 *
 * WHY THIS EXISTS
 * ---------------
 * Four separate places resolved the target as
 * `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`:
 *
 *   playwright.config.ts                          (baseURL for every spec)
 *   tests/e2e/global-setup.ts                     (the LOGIN + storageState)
 *   tests/e2e/workflows/_fixtures.ts              (fixture CREATE/DELETE)
 *   tests/e2e/spec-coverage/usage-analytics-page.spec.ts
 *
 * On a developer box `:8080` is the SHARED dev container, which bind-mounts
 * real host checkouts. With `NEXTCLOUD_URL` unset — the default — those
 * fallbacks silently pointed the suite at it, and two of the four are WRITE
 * paths: the fixtures create and delete catalogs and publications, and the
 * global setup fires repeated admin logins (which is how another app in this
 * fleet triggered brute-force lockouts in somebody else's environment).
 * Nothing fails; the run just quietly happens somewhere else.
 *
 * A literal default is what makes that possible, so there is none. Unset means
 * an immediate, loud error.
 *
 * ⚠️ THE NAME MATTERS. A sibling repo adopted a `PLAYWRIGHT_BASE_URL`-only
 * resolver during its own Vue 3 migration and its CI E2E job has hard-failed on
 * every run since with `PLAYWRIGHT_BASE_URL is not set`. The shared
 * `ConductionNL/.github` quality workflow exports **`BASE_URL`**,
 * **`NEXTCLOUD_URL`** and **`NC_BASE_URL`** (verified in
 * `.github/workflows/quality.yml`), not `PLAYWRIGHT_BASE_URL`. All four names
 * are therefore accepted; only the hardcoded fallback is gone.
 */

const CANDIDATES = [
	'PLAYWRIGHT_BASE_URL',
	'BASE_URL',
	'NEXTCLOUD_URL',
	'NC_BASE_URL',
] as const

/**
 * Resolve the Nextcloud base URL for the e2e suite.
 *
 * @throws {Error} When none of the accepted environment variables is set.
 * @return {string} The base URL, without a trailing slash.
 */
export function resolveBaseUrl(): string {
	for (const name of CANDIDATES) {
		const value = process.env[name]
		if (value && value.trim() !== '') {
			return value.trim().replace(/\/+$/, '')
		}
	}

	throw new Error(
		'No Nextcloud base URL configured for the e2e suite. Set one of '
		+ CANDIDATES.join(', ')
		+ ' — e.g. PLAYWRIGHT_BASE_URL=http://localhost:8086 for the isolated '
		+ 'opencatalogi-vue3-e2e instance. There is deliberately no default: '
		+ 'the old fallback was http://localhost:8080, the SHARED dev container.',
	)
}

/** The resolved base URL. Importing this module without a target set throws. */
export const BASE_URL = resolveBaseUrl()
