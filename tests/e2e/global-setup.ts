/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/admin.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import { resolveBaseUrl } from './base-url'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'opencatalogi-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/opencatalogi/`.
 *
 * CI runs `npm ci` + `npx playwright install` but not `npm run build`,
 * so on a fresh runner the JS bundle is missing and the rendered page
 * loads a 404 script tag. Locally, the app in the dev container is
 * mounted from a separate checkout, so this build only helps CI / a
 * checkout that serves its own `js/`.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	// On CI this is a hard error, not something to repair.
	//
	// The shared workflow has already run its own "Build app frontend" step by
	// the time we get here, so a missing bundle means that step did not produce
	// one — and silently rebuilding turns a broken build into a green run with
	// nothing to show for it. It also makes the bundle genuinely untestable:
	// a positive control that removes the bundle to prove the specs depend on it
	// gets healed right back before the first spec runs, and the suite passes.
	// (Observed: run 30791459241 passed 82/82 with the bundle deleted, because
	// this function rebuilt it — the control proved nothing until it was changed
	// to truncate the file instead.)
	//
	// Locally the rebuild stays, because there it is a genuine convenience:
	// a fresh checkout has no `js/` and nothing else is going to build it.
	if (process.env.CI === 'true' || process.env.GITHUB_ACTIONS === 'true') {
		throw new Error(
			`[playwright globalSetup] bundle missing at ${BUNDLE_PATH} on CI. `
				+ 'The workflow\'s "Build app frontend" step should already have produced it — '
				+ 'check that step rather than rebuilding here, because a rebuild would hide it.',
		)
	}
	// eslint-disable-next-line no-console
	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, {
			failOnStatusCode: false,
		})
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. `
					+ 'Make sure the docker container is running and reachable.',
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	// ⚠️ This function performs the ADMIN LOGIN. Its previous
	// `?? 'http://localhost:8080'` tail fired repeated admin logins at the
	// SHARED dev container whenever no target was configured — the mechanism by
	// which another app in this fleet triggered brute-force lockouts in
	// somebody else's environment. `resolveBaseUrl()` throws instead.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? resolveBaseUrl()
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(username)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
				+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).',
		)
	}

	// Bake the nc-vue support-dialog "seen" flag into the persisted storage
	// state so CnSupportDialog never auto-opens during a spec.
	//
	// WHY THIS BELONGS IN globalSetup AND NOT IN A dismiss() HELPER
	// ------------------------------------------------------------
	// CnAppRoot calls `useSupportDialog(appId, { persistence: 'server' })`
	// (nextcloud-vue CnAppRoot.vue:1297). In server mode `visible` starts
	// FALSE and only flips true after `GET /apps/opencatalogi/api/preferences/
	// support-dialog-seen` resolves — i.e. ASYNCHRONOUSLY, at an arbitrary
	// point after the page has already rendered and a spec has already started
	// clicking. The modal then mounts a full-viewport `.modal-mask` that
	// swallows pointer events, and Playwright retries the click until the test
	// times out. Measured on run 31167878145: catalog-detail-page.spec.ts:56
	// burned its whole 60s budget with the call log naming
	// `data-testid-modal="cn-support-dialog" … subtree intercepts pointer
	// events` while the modal was still `modal-in-enter-active`. It was
	// reported as `1 flaky` — a retry-masked failure, not a healthy pass.
	//
	// A dismissal helper cannot close this race: whatever it does runs BEFORE
	// the preferences GET lands. `resolveServerVisibility()` however checks the
	// local flag FIRST and returns early when it reads exactly '1'
	// (useSupportDialog.js `hasRealFlag`), so seeding the flag suppresses the
	// dialog before it can ever be scheduled.
	//
	// This is not weakening the suite: no spec asserts on CnSupportDialog, it
	// is an nc-vue one-time nag unrelated to any OpenCatalogi scenario, and the
	// `visual` project already hides it (tests/e2e/visual/_visual-helpers.ts).
	// globalSetup only ever visits /index.php/login, so the flag was never
	// present in admin.json and the dialog was armed on every single spec.
	await page.evaluate(() => {
		try {
			window.localStorage.setItem('cn-support-dialog-shown:opencatalogi', '1')
		} catch (e) {
			/* private mode / quota — the dismissOverlays fallback still applies */
		}
	})

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
