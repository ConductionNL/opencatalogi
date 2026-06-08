/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
import { type Page, expect } from '@playwright/test'

export const APP = '/index.php/apps/opencatalogi'

/** Dismiss the Nextcloud first-run wizard if it pops up over the app. */
export async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard.getByRole('button', { name: /close|got it|finish|skip/i }).first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
}

/** Boot the SPA at its root and wait for the navigation shell to render. */
export async function bootApp(page: Page): Promise<void> {
	await page.goto(`${APP}/`, { waitUntil: 'domcontentloaded' }).catch(() => {})
	await dismissOverlays(page)
	// CnAppNav rendered → shell is up.
	await expect(page.locator('[data-testid="cn-nav"]').first()).toBeVisible({ timeout: 20000 })
}

/** Open the NcAppNavigationSettings gear foldout (holds settings-section nav). */
export async function openSettingsFoldout(page: Page): Promise<void> {
	// The foldout caption button. NC renders it as a button inside the
	// settings entry; clicking it slides the panel open. Idempotent —
	// if a settings entry is already visible we skip.
	const anySettingsEntry = page.locator('[data-testid="cn-nav-entry-CatalogsMenu"]').first()
	if (await anySettingsEntry.isVisible().catch(() => false)) return
	const gear = page.locator(
		'.app-navigation-entry__settings-button, button.settings-button, '
		+ '.app-navigation__settings-button, .app-navigation-settings > button, '
		+ '.app-navigation__settings button',
	).first()
	if (await gear.isVisible().catch(() => false)) {
		await gear.click().catch(() => {})
		await page.waitForTimeout(500)
	}
}

/**
 * Click a CnAppNav entry by its manifest menu id and wait for the content
 * area to settle. `settings` opens the gear foldout first.
 */
export async function navTo(page: Page, menuId: string, settings = false): Promise<void> {
	if (settings) await openSettingsFoldout(page)
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
