import type { Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared navigation + assertion helpers for the OpenCatalogi behavioral
 * e2e suite.
 *
 * Why nav-clicks, not deep-link goto: OpenCatalogi is a manifest-shell
 * (CnAppRoot) SPA. A hard `page.goto('/apps/opencatalogi/catalogi')`
 * loads the SPA index template which boots the router at `/` — the
 * deep-link path is dropped and the Dashboard renders instead. So every
 * page MUST be reached by clicking its CnAppNav entry
 * (`[data-testid="cn-nav-entry-<menuId>"]`). Settings-section entries
 * live inside the NcAppNavigationSettings foldout, which must be opened
 * first.
 */
import { expect } from '@playwright/test'

export const APP = '/index.php/apps/opencatalogi'

/**
 * Dismiss overlays that sit above the app and swallow pointer events.
 *
 * Three distinct ones exist:
 *  - Nextcloud's own `#firstrunwizard`.
 *  - nc-vue's CnSupportDialog (`[data-testid-modal="cn-support-dialog"]`),
 *    auto-opened once per browser by `useSupportDialog()` — see
 *    nextcloud-vue/src/composables/useSupportDialog.js, which gates on
 *    `localStorage['cn-support-dialog-shown:opencatalogi']`. Whether the flag
 *    survives into a run depends on what `globalSetup` happened to persist
 *    into `storageState`, so the dialog appears INTERMITTENTLY — which is
 *    exactly what it looked like: run 31167878145 reported `1 flaky`, and the
 *    call log named the culprit outright ("<div class=modal-wrapper …
 *    data-testid-modal=cn-support-dialog> subtree intercepts pointer events",
 *    logged while the modal was still `modal-in-enter-active`). The nav click
 *    in catalog-detail-page.spec.ts then retried for the full 60s timeout and
 *    read like a broken navigation entry.
 *    The `visual` project already knew about this overlay
 *    (tests/e2e/visual/_visual-helpers.ts:dismissSupportDialog) and so does
 *    DocuDesk's equivalent helper; only THIS regression helper was missing it.
 *  - nc-vue's CnWalkthrough ("Welcome to OpenCatalogi"), which paints a
 *    full-screen `.cn-walkthrough__dim--full` scrim. The scrim does NOT hide
 *    the page, so every `toBeVisible()` still passes and the DOM looks
 *    healthy — but any `click()` is intercepted, and Playwright retries until
 *    it times out. That failure reads like a broken button rather than an
 *    overlay, so dismiss it explicitly.
 */
export async function dismissOverlays(page: Page): Promise<void> {
	// The non-gating setup wizard, when storage seeding did not take (a
	// blocked-storage context, or a page opened without bootApp). Identified by
	// the step markup CnSetupWizard renders inside CnWizardDialog rather than
	// by a title, which is translated.
	const setupWizard = page
		.locator('[role="dialog"]')
		.filter({ has: page.locator('.cn-setup-step') })
		.first()
	if (await setupWizard.isVisible().catch(() => false)) {
		const skip = setupWizard
			.getByRole('button', { name: /cancel|close|skip|later|annuleren/i })
			.first()
		if (await skip.isVisible().catch(() => false)) {
			await skip.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await setupWizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}

	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard
			.getByRole('button', { name: /close|got it|finish|skip/i })
			.first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}

	// Loop rather than a single `if`: the dialog animates in, so a check that
	// lands one frame early sees nothing and a close click one frame early is
	// swallowed by the same enter transition.
	const support = page.locator('[data-testid-modal="cn-support-dialog"]').first()
	for (let i = 0; i < 3 && (await support.isVisible().catch(() => false)); i++) {
		const close = support
			.locator(
				'.modal-container__close, [aria-label="Close"], button:has-text("Close")',
			)
			.first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await support.waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {})
	}
	// The mask outlives the dialog's own visibility by one transition; a click
	// issued in that window is still intercepted. Wait for it to detach.
	await page
		.locator('.cn-support-dialog.modal-mask')
		.waitFor({ state: 'detached', timeout: 3000 })
		.catch(() => {})

	const walkthrough = page.locator('.cn-walkthrough').first()
	if (await walkthrough.isVisible().catch(() => false)) {
		const skip = walkthrough
			.getByRole('button', { name: /skip|close|finish|done|got it|later/i })
			.first()
		if (await skip.isVisible().catch(() => false)) {
			await skip.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await walkthrough.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {})
	}
}

/** Boot the SPA at its root and wait for the navigation shell to render. */
export async function bootApp(page: Page): Promise<void> {
	// Stand the non-gating setup wizard down BEFORE the app boots.
	//
	// CnAppRoot opens it whenever the server reports an optional setup step
	// as outstanding, and opencatalogi declares several. It renders as a
	// modal over the shell, so every click behind it — the nav entries these
	// specs drive — times out rather than failing by name. Dismissing it
	// reactively is a race against its enter transition; seeding the key it
	// reads is not.
	//
	// The key is versioned (`cn-setup-wizard-dismissed:<appId>:<setup.version>`),
	// so a range is seeded: bumping manifest.setup.version must not silently
	// re-open the wizard across the whole suite.
	await page.addInitScript(() => {
		try {
			for (let v = 0; v <= 20; v++) {
				window.localStorage.setItem(
					`cn-setup-wizard-dismissed:opencatalogi:${v}`,
					'1',
				)
			}
		} catch (e) {
			/* storage blocked — dismissOverlays() is the fallback */
		}
	})
	await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' }).catch(() => {})
	await dismissOverlays(page)
	// CnAppNav rendered → shell is up.
	await expect(page.locator('[data-testid="cn-nav"]').first()).toBeVisible({
		timeout: 20000,
	})
}

/**
 * Open the NcAppNavigationSettings gear foldout (holds settings-section nav).
 *
 * The foldout's toggle MUST be located via the stable `cn-nav-settings`
 * testid. @nextcloud/vue v9 renders NcAppNavigationSettings with CSS-MODULE
 * class names (`_container_Cocg7`, `_header_cORcE`, `_content_IA45l`) whose
 * hashes change between builds, so the v8-era selectors below
 * (`.app-navigation-settings > button` and friends) match ZERO elements — the
 * helper silently did nothing and every settings entry stayed `display: none`.
 * They are kept only as a fallback for older shells.
 */
export async function openSettingsFoldout(page: Page): Promise<void> {
	const container = page.locator('[data-testid="cn-nav-settings"]').first()
	if ((await container.count()) > 0) {
		// Exactly one toggle button lives in the foldout header; it carries
		// aria-expanded, which also tells us whether it is already open.
		const toggle = container.locator('button[aria-expanded]').first()
		if ((await toggle.count()) > 0) {
			if ((await toggle.getAttribute('aria-expanded')) === 'true') return
			await toggle.click().catch(() => {})
			await expect(toggle).toHaveAttribute('aria-expanded', 'true', {
				timeout: 5000,
			})
			return
		}
	}

	// Fallback: pre-v9 shells with stable settings-button class names.
	const gear = page
		.locator(
			'.app-navigation-entry__settings-button, button.settings-button, '
				+ '.app-navigation__settings-button, .app-navigation-settings > button, '
				+ '.app-navigation__settings button',
		)
		.first()
	if (await gear.isVisible().catch(() => false)) {
		await gear.click().catch(() => {})
		await page.waitForTimeout(500)
	}
}

/**
 * Expand the collapsible CnAppNav group that owns `menuId`, if there is one.
 *
 * Manifest `children` are rendered into NcAppNavigationItem's `allow-collapse`
 * slot, i.e. a `<ul class="app-navigation-entry__children">` that is
 * `display: none` until the group is open. CnAppNav's `isItemOpen()` resolves
 * to open ONLY when the user has toggled it, when a child is the ACTIVE route,
 * or when the manifest entry sets `open: true`. On a cold boot at `#/` none of
 * those hold, so every grouped entry (`CatalogsMenu`, `ThemesMenu`,
 * `GlossaryMenu`, `PagesMenu`, `MenusMenu`, `WooBatchesMenu`) starts hidden —
 * present in the DOM, zero-sized, `offsetParent === null`.
 *
 * `openSettingsFoldout` cannot help here: these entries are in a collapsible
 * GROUP, not in the NcAppNavigationSettings gear foldout, and none of that
 * helper's gear selectors match anything in this app's DOM. Clicking the
 * group's own entry link toggles it open.
 */
export async function expandGroupFor(page: Page, menuId: string): Promise<void> {
	const entry = page.locator(`[data-testid="cn-nav-entry-${menuId}"]`).first()
	if (await entry.isVisible().catch(() => false)) return

	const group = page
		.locator(
			`li.app-navigation-entry--collapsible:has([data-testid="cn-nav-entry-${menuId}"])`,
		)
		.first()
	if ((await group.count()) === 0) return

	// The group's OWN link is the first `.app-navigation-entry-link` inside it;
	// child links live deeper, inside `.app-navigation-entry__children`.
	const toggle = group.locator('.app-navigation-entry-link').first()
	if (await toggle.isVisible().catch(() => false)) {
		await toggle.click()
		await expect(entry).toBeVisible({ timeout: 10000 })
	}
}

/**
 * Click a CnAppNav entry by its manifest menu id and wait for the content
 * area to settle. `settings` opens the gear foldout first; grouped entries
 * additionally need their parent group expanded.
 */
export async function navTo(
	page: Page,
	menuId: string,
	settings = false,
): Promise<void> {
	if (settings) await openSettingsFoldout(page)
	await expandGroupFor(page, menuId)
	const entry = page.locator(`[data-testid="cn-nav-entry-${menuId}"]`).first()
	await expect(entry).toBeVisible({ timeout: 10000 })
	await entry.click()
	await page.waitForTimeout(1500)
	await dismissOverlays(page)
}

/** The app's main content host (inside the CnAppRoot router-view). */
export function content(page: Page) {
	return page.locator('.app-content, main').first()
}

/** Collect uncaught JS errors during a test for a no-fatal-error assertion. */
export function trackPageErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('pageerror', (e) => errors.push(e.message))
	return errors
}

export function fatalErrors(errors: string[]): string[] {
	return errors.filter((e) => !/warning|warn|deprecat|ResizeObserver/i.test(e))
}

/**
 * Open an in-app page by its hash ROUTE rather than by clicking a nav entry.
 *
 * Six concepts were retired from this app's navigation in
 * `src/menu-layout.json#removals` — Organizations (an OpenRegister concept),
 * Glossary / Themes / Pages / Menus (Portaliq's, per ADR-086), and WOO (an
 * object type, not a section). `removals` deliberately drops only the MENU
 * ENTRY: every page stays registered on the router precisely so deep links
 * and these specs keep working. There is simply no longer a
 * `[data-testid="cn-nav-entry-…"]` to click for them.
 *
 * This is the hash form, which is what this SPA actually routes on — the
 * same mechanism `gate19.spec.ts#gotoHash` has always used. The warning at
 * the top of this file is about PATH-style deep links
 * (`/apps/opencatalogi/glossary`), which do land on the Dashboard because
 * the server template boots the router at `/`. `#/glossary` does not.
 *
 * @param page The Playwright page.
 * @param route The in-app route, leading slash included (e.g. '/glossary').
 */
export async function navToRoute(page: Page, route: string): Promise<void> {
	await page.goto(`${APP}${route}`, { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(1500)
	await dismissOverlays(page)
	await expect(page.locator('[data-testid="cn-nav"]').first()).toBeVisible({
		timeout: 20000,
	})
}
