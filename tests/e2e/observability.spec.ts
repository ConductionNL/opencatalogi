/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * prometheus-metrics tasks 6.1-6.6: the live checks of `/api/metrics` and
 * `/api/health`, which OpenRegister's AppHost engine serves from the
 * `observability` block of src/manifest.json plus OpenCatalogiMetricsProvider
 * and SearchBackendHealthProvider.
 *
 * WHAT THIS SPEC IS LOOKING FOR.
 * - As administrator, `/api/metrics` answers Prometheus text
 *   (`text/plain; version=0.0.4`), with `# HELP` and `# TYPE` for every family.
 * - Anonymously it answers 401, 403 or the login redirect, and no metric line.
 * - `/api/health` answers `status`, `version` and `checks`, with
 *   `checks.search_backend` present.
 * - `opencatalogi_info` carries non-empty `nextcloud_version`, `php_version`
 *   and `version` labels, `opencatalogi_up` is 1, and the directory and
 *   federation families are present (their values may be 0 without federation).
 *
 * WHAT IT CREATES AND REMOVES. Nothing; it only reads.
 *
 * @spec openspec/changes/prometheus-metrics/tasks.md
 */

import { expect, test } from '@playwright/test'

const METRICS = '/index.php/apps/opencatalogi/api/metrics'
const HEALTH = '/index.php/apps/opencatalogi/api/health'

/**
 * The metric family names a Prometheus text body declares with `# TYPE`.
 *
 * @param body The exposition text.
 * @return The family names.
 */
function typedFamilies(body: string): string[] {
	return body.split('\n').filter((line) => line.startsWith('# TYPE ')).map((line) => line.split(' ')[2])
}

/**
 * The labels of the first sample line of a family, as a map.
 *
 * @param body The exposition text.
 * @param family The metric name.
 * @return The labels, empty when the family has no labelled sample.
 */
function labelsOf(body: string, family: string): Record<string, string> {
	const line = body.split('\n').find((candidate) => candidate.startsWith(`${family}{`))
	const labels: Record<string, string> = {}
	for (const match of (line ?? '').matchAll(/(\w+)="([^"]*)"/g)) {
		labels[match[1]] = match[2]
	}
	return labels
}

test.describe('Observability endpoints', () => {
	let body = ''

	test('6.1 an administrator reads the metrics as Prometheus text', async ({ request }) => {
		const res = await request.get(METRICS)
		expect(res.status(), await res.text()).toBe(200)
		expect(res.headers()['content-type'] ?? '').toContain('text/plain; version=0.0.4')
		body = await res.text()

		const families = typedFamilies(body)
		expect(families.length).toBeGreaterThan(2)
		for (const family of families) {
			expect(body, `HELP for ${family}`).toContain(`# HELP ${family} `)
		}
	})

	test('6.2 an anonymous caller gets no metrics', async ({ playwright, baseURL }) => {
		const anonymous = await playwright.request.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		try {
			const res = await anonymous.get(METRICS, { maxRedirects: 0 })
			expect([302, 303, 401, 403]).toContain(res.status())
			expect(await res.text()).not.toContain('opencatalogi_up')
		} finally {
			await anonymous.dispose()
		}
	})

	test('6.3 the health answer carries status, version and the search backend check', async ({ request }) => {
		const res = await request.get(HEALTH)
		expect([200, 503]).toContain(res.status())
		const health = await res.json()
		expect(typeof health.status).toBe('string')
		expect(String(health.version ?? '')).not.toBe('')
		expect(health.checks).toBeTruthy()
		expect(Object.keys(health.checks)).toContain('search_backend')
	})

	test('6.4 opencatalogi_info names the versions', async ({ request }) => {
		const text = body || await (await request.get(METRICS)).text()
		const labels = labelsOf(text, 'opencatalogi_info')
		for (const key of ['nextcloud_version', 'php_version', 'version']) {
			expect(labels[key] ?? '', key).not.toBe('')
		}
	})

	test('6.5 opencatalogi_up is 1', async ({ request }) => {
		const text = body || await (await request.get(METRICS)).text()
		expect(text.split('\n')).toContain('opencatalogi_up 1')
	})

	test('6.6 the directory and federation families are present', async ({ request }) => {
		const text = body || await (await request.get(METRICS)).text()
		const families = typedFamilies(text)
		for (const family of [
			'opencatalogi_directory_entries_total',
			'opencatalogi_federation_reachable_total',
			'opencatalogi_federation_unreachable_total',
		]) {
			expect(families, family).toContain(family)
		}
	})
})
