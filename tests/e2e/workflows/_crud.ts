/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared UI-driving helpers for the CnIndexPage-backed CRUD-with-persistence
 * workflow specs. These click through the genuine user journey (Add CTA →
 * schema form → submit; row action menu → Edit / Delete dialogs) on the real
 * CnIndexPage surface that OpenCatalogi mounts for its list pages.
 *
 * data-testids used (all from @conduction/nextcloud-vue):
 *   [data-testid="cn-index-page"]      — the index surface root
 *   [data-testid="cn-cta-primary"]     — the "Add" CTA
 *   [data-testid="cn-object-row"]      — a table row
 *   [data-testid="cn-row-actions"]     — the per-row NcActions trigger
 *   [data-testid="cn-action-item-edit"]   — Edit action in the row menu
 *   [data-testid="cn-action-item-delete"] — Delete action in the row menu
 *   [data-testid="cn-modal"]           — the form / delete dialog modal
 *
 * The create/edit form is CnFormDialog: schema-driven NcTextField inputs
 * labelled by the schema property title ("Title", "Summary", …) and a primary
 * confirm button labelled "Create" / "Save".
 */
import { type Page, expect } from '@playwright/test'

/** Wait for the index body to settle into rows or the empty state. */
export async function waitIndexBody(page: Page): Promise<void> {
	const index = page.locator('[data-testid="cn-index-page"]').first()
	const body = index.locator(
		'[data-testid="cn-object-list-table"], table, .cn-card-grid, '
		+ '[data-testid="cn-object-list-empty"], .empty-content, [class*="empty-content"]',
	).filter({ visible: true }).first()
	await expect(body).toBeVisible({ timeout: 20000 })
}

/** The open form/confirm dialog (CnFormDialog / CnDeleteDialog → NcDialog). */
export function dialog(page: Page) {
	return page.locator('[role="dialog"]').last()
}

/**
 * Open the Add CTA, fill the schema form's Title field (and any extra fields),
 * and confirm. Returns once the dialog has closed.
 */
export async function createViaForm(
	page: Page,
	title: string,
	extra: Record<string, string> = {},
): Promise<void> {
	await page.locator('[data-testid="cn-cta-primary"]').first().click()
	const modal = page.locator('[role="dialog"]').filter({ hasText: /create/i }).first()
	await expect(modal).toBeVisible({ timeout: 10000 })

	await fillField(modal, 'Title', title)
	for (const [label, value] of Object.entries(extra)) {
		await fillField(modal, label, value).catch(() => {})
	}

	await modal.getByRole('button', { name: /^create$/i }).first().click()
	await expect(modal).toBeHidden({ timeout: 15000 })
}

/** Fill an NcTextField in a dialog, located by its visible label. */
export async function fillField(
	scope: ReturnType<Page['locator']>,
	label: string,
	value: string,
): Promise<void> {
	// NcTextField renders <label>Title</label> wired to an <input>. Target the
	// input via the label's accessible name; fall back to nth text input.
	const byLabel = scope.getByLabel(new RegExp(`^${label}\\s*\\*?$`, 'i')).first()
	if (await byLabel.count() > 0 && await byLabel.isVisible().catch(() => false)) {
		await byLabel.fill(value)
		return
	}
	// Fallback: the first visible text input in the dialog is Title.
	const firstText = scope.locator('input[type="text"]:visible').first()
	await firstText.fill(value)
}

/** Find the row whose text contains the given title. */
export function rowByTitle(page: Page, title: string) {
	return page.locator('[data-testid="cn-object-row"]').filter({ hasText: title }).first()
}

/** Open a row's action menu and click an action (edit / delete). */
export async function rowAction(page: Page, title: string, action: 'edit' | 'delete'): Promise<void> {
	const row = rowByTitle(page, title)
	await expect(row).toBeVisible({ timeout: 10000 })
	const trigger = row.locator('[data-testid="cn-row-actions"] button, [data-testid="cn-row-actions"]').first()
	await trigger.click()
	// NcActions renders its menu in a portal; the item carries a stable testid.
	const item = page.locator(`[data-testid="cn-action-item-${action}"]`).first()
	await expect(item).toBeVisible({ timeout: 8000 })
	await item.click()
}
