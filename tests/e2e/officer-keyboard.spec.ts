/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The officer flow works from the keyboard alone (officer-flow-accessibility, REQ-OFA-002).
 *
 * With only Tab, Shift+Tab, Enter, Space, Escape and the arrow keys, an officer
 * creates a publication with a title and a Woo category, attaches a document,
 * publishes it and withdraws it with a reason. The one step that is not the
 * officer's, the review (submit and approve), is a reviewer's and is taken
 * through the lifecycle API, as the CI seed does.
 *
 * Every focus change is checked: the focused element (or, for a visually
 * hidden switch input, the switch drawn for it) is visible, inside the
 * viewport, and its outline or box-shadow differs from what it shows once
 * focus has moved on. While a dialog is open, focus stays inside it, and a
 * dialog closed with its own button or Escape gives focus back to its opener.
 *
 * @e2e openspec/changes/officer-flow-accessibility/specs/officer-accessibility/spec.md#requirement-the-officer-flow-works-from-the-keyboard-alone-req-ofa-002 REQ-OFA-002
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const TITLE = `Toetsenbordpublicatie ${Date.now()}`
const STEPS = 150

/** What a focus change looked like, for the indicator and trap checks. */
type Focus = {
	name: string
	inApp: boolean
	visible: boolean
	inViewport: boolean
	inDialog: boolean
	dialogOpen: boolean
	indicator: string
	previousIndicator: string | null
	previousNowUnfocused: string | null
}

/**
 * Describe the focused element in the page. The element that last had focus
 * is kept on `window`, so its unfocused style can be read once focus moved on.
 */
async function focusState(page: Page): Promise<Focus> {
	return page.evaluate(() => {
		const indicatorOf = (element: Element): string => {
			// The element and the wrappers a component draws its focus on (a
			// switch's track, a select's toggle), up to three levels up.
			const parts: string[] = []
			let node: Element | null = element
			for (let level = 0; node && level < 4; level++, node = node.parentElement) {
				const style = getComputedStyle(node)
				const outline = style.outlineStyle === 'none' ? 'none' : [style.outlineStyle, style.outlineWidth, style.outlineColor].join(' ')
				parts.push(`${outline} | ${style.boxShadow}`)
			}
			return parts.join(' / ')
		}
		const drawn = (element: Element): Element => {
			const rect = element.getBoundingClientRect()
			const hidden = rect.width <= 1 || rect.height <= 1 || getComputedStyle(element).opacity === '0'
			return hidden ? (element.closest('.checkbox-radio-switch') ?? element.parentElement ?? element) : element
		}
		const memory = window as unknown as { __kbPrevious?: Element | null; __kbPreviousIndicator?: string }
		const active = document.activeElement ?? document.body
		const shown = drawn(active)
		const rect = shown.getBoundingClientRect()
		const dialogs = Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]')).filter((dialog) => dialog.getBoundingClientRect().height > 0)
		const top = dialogs[dialogs.length - 1]
		const previous = memory.__kbPrevious ?? null
		const inApp = Boolean(active.closest('#content-vue, [role="dialog"]'))
		const state = {
			name: (active.getAttribute('aria-label')
				|| (active as HTMLInputElement).labels?.[0]?.innerText
				|| (active as HTMLElement).innerText
				|| (active as HTMLInputElement).value
				|| '').trim().replace(/\s+/g, ' ').slice(0, 60),
			inApp,
			visible: rect.width > 0 && rect.height > 0 && getComputedStyle(shown).visibility !== 'hidden',
			inViewport: rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight && rect.left < window.innerWidth,
			inDialog: top ? top.contains(active) : false,
			dialogOpen: Boolean(top),
			indicator: indicatorOf(active),
			previousIndicator: memory.__kbPreviousIndicator ?? null,
			previousNowUnfocused: previous && previous !== active && previous.isConnected ? indicatorOf(previous) : null,
		}
		memory.__kbPrevious = inApp ? active : null
		memory.__kbPreviousIndicator = state.indicator
		return state
	})
}

/** Press one key and check the focus it lands on; answer the focused element's name. */
async function press(page: Page, key: string): Promise<string> {
	const dialogBefore = await page.locator('[role="dialog"][aria-modal="true"]:visible').count()
	await page.keyboard.press(key)
	await page.waitForTimeout(60)
	const state = await focusState(page)
	// Nextcloud's header and app navigation are the server's, not this app's.
	if (!state.inApp) return state.name
	expect.soft(state.visible, `"${state.name}" is visible when focused`).toBe(true)
	expect.soft(state.inViewport, `"${state.name}" is inside the viewport when focused`).toBe(true)
	if (state.previousIndicator !== null && state.previousNowUnfocused !== null) {
		expect.soft(state.previousNowUnfocused, `the element before "${state.name}" showed a focus indicator`).not.toBe(state.previousIndicator)
	}
	if ((key === 'Tab' || key === 'Shift+Tab') && dialogBefore > 0 && state.dialogOpen) {
		expect(state.inDialog, `focus stays inside the open dialog (now on "${state.name}")`).toBe(true)
	}
	return state.name
}

/** Tab (or Shift+Tab) until the focused element's name is `name`, or starts with it unless `exact`. */
async function tabTo(page: Page, name: string, back = false, exact = false): Promise<void> {
	for (let step = 0; step < STEPS; step++) {
		const focused = await press(page, back ? 'Shift+Tab' : 'Tab')
		if (exact ? focused === name : focused.startsWith(name)) return
	}
	throw new Error(`"${name}" cannot be reached with ${back ? 'Shift+Tab' : 'Tab'}: it needs the mouse`)
}

/** Arrow down through an open menu until the item named `name` has focus. */
async function arrowTo(page: Page, name: string): Promise<void> {
	for (let step = 0; step < 20; step++) {
		if (await press(page, 'ArrowDown') === name) return
	}
	throw new Error(`the menu item "${name}" cannot be reached with the arrow keys`)
}

/** Opening a dialog moves focus into it: wait until it has. */
async function focusMovesIntoDialog(page: Page, what: string): Promise<void> {
	await expect.poll(async () => (await focusState(page)).inDialog, { message: `opening ${what} moves focus into it`, timeout: 10_000 }).toBe(true)
}

/** The focused element's name. */
async function focusedName(page: Page): Promise<string> {
	return (await focusState(page)).name
}

/** The publication this walk created, through the objects API. */
async function created(request: APIRequestContext): Promise<{ id: string } | null> {
	const res = await request.get(`${OBJECTS}/publication/publication?_search=${encodeURIComponent(TITLE)}&_limit=5`)
	if (!res.ok()) return null
	const rows = ((await res.json()).results ?? []) as Array<Record<string, unknown>>
	const row = rows.find((candidate) => JSON.stringify(candidate.title ?? '').includes(TITLE))
	return row ? { id: String(row.id) } : null
}

test.describe('The officer flow works from the keyboard alone (REQ-OFA-002)', () => {
	test.setTimeout(240_000)

	test.afterAll(async ({ request }) => {
		const publication = await created(request)
		if (publication) await request.delete(`${OBJECTS}/publication/publication/${publication.id}`)
	})

	test('create, attach, publish and withdraw by keyboard', async ({ page, request }) => {
		const catalog = await request.get(`${OBJECTS}/publication/catalog?_limit=1`)
		const slug = String(((await catalog.json()).results ?? [])[0]?.slug ?? '')
		expect(slug, 'the instance holds a catalog').not.toBe('')

		await page.goto(`${APP}/publications/${slug}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await page.waitForLoadState('networkidle').catch(() => undefined)

		await test.step('create with a title and a category', async () => {
			await tabTo(page, 'Add Publication')
			await press(page, 'Enter')
			await focusMovesIntoDialog(page, 'the publication dialog')
			for (const [field, text] of [['Description', 'Gemaakt met alleen het toetsenbord.'], ['Summary', 'Toetsenbord.'], ['Title', TITLE]]) {
				await tabTo(page, field)
				await press(page, 'Enter')
				await page.keyboard.type(text)
			}
			await tabTo(page, 'Woo information category')
			await press(page, 'Enter')
			await press(page, 'Tab')
			await press(page, 'ArrowDown')
			await press(page, 'Enter')
			await tabTo(page, 'Create')
			await press(page, 'Enter')
			await expect.poll(async () => (await created(request)) !== null, { message: 'the publication is saved' }).toBe(true)
			await expect.soft(page.getByRole('dialog').first(), 'the dialog stays open on the new publication').toBeVisible()
			expect.soft((await focusState(page)).inDialog, 'after Create, focus stays in the dialog').toBe(true)
		})

		await test.step('attach a document', async () => {
			page.once('filechooser', (chooser) => chooser.setFiles({ name: 'besluit.txt', mimeType: 'text/plain', buffer: Buffer.from('Besluit') }))
			await tabTo(page, 'Add File', true)
			await press(page, 'Enter')
			await focusMovesIntoDialog(page, 'the attachment dialog')
			await page.keyboard.type('No label')
			await press(page, 'Enter')
			await tabTo(page, 'Add a file')
			await press(page, 'Enter')
			await expect(page.getByText('besluit.txt')).toBeVisible({ timeout: 15_000 })
			await tabTo(page, 'Done')
			await press(page, 'Enter')
			expect(await focusedName(page), 'closing the attachment dialog gives focus back to its opener').toBe('Add File')
		})

		await test.step('close the publication dialog', async () => {
			await press(page, 'Escape')
			expect(await focusedName(page), 'closing the publication dialog gives focus back to its opener').toBe('Add Publication')
		})

		const publication = await created(request)
		expect(publication).not.toBeNull()
		await test.step('a reviewer approves (lifecycle API)', async () => {
			for (const action of ['submit', 'approve']) {
				const res = await request.post(`${OBJECTS}/${publication?.id}/transition`, { data: { action, data: {} } })
				expect(res.ok(), `${action} answers 2xx`).toBe(true)
			}
		})

		await test.step('open the publication and publish it', async () => {
			await tabTo(page, TITLE)
			await press(page, 'Enter')
			await page.waitForURL(new RegExp(String(publication?.id)))
			await page.waitForLoadState('networkidle').catch(() => undefined)
			// The page's Actions menu holds the lifecycle moves.
			await tabTo(page, 'Edit', false, true)
			await tabTo(page, 'Actions', false, true)
			await press(page, 'Enter')
			await arrowTo(page, 'Publish')
			await press(page, 'Enter')
			await focusMovesIntoDialog(page, 'the publish dialog')
			if (await focusedName(page) !== 'Publication Date') await tabTo(page, 'Publication Date', false, true)
			await page.keyboard.type(new Date(Date.now() - 60_000).toISOString())
			await tabTo(page, 'Publish', false, true)
			await press(page, 'Enter')
			await expect.poll(async () => {
				const res = await request.get(`/index.php/apps/opencatalogi/api/publications/${publication?.id}/visibility`)
				return res.ok() ? (await res.json()).state : null
			}, { message: 'the publication is public', timeout: 20_000 }).toBe('public')
		})

		await test.step('withdraw with a reason', async () => {
			await tabTo(page, 'Withdraw', false, true)
			await press(page, 'Enter')
			await focusMovesIntoDialog(page, 'the withdraw dialog')
			await tabTo(page, 'Reason', false, true)
			await page.keyboard.type('Met het verkeerde besluit gepubliceerd.')
			await tabTo(page, 'Withdraw', false, true)
			await press(page, 'Enter')
			await expect.poll(async () => {
				const res = await request.get(`/index.php/apps/opencatalogi/api/publications/${publication?.id}/visibility`)
				return res.ok() ? (await res.json()).state : null
			}, { message: 'the publication ends withdrawn', timeout: 20_000 }).toBe('withdrawn')
		})
	})
})
