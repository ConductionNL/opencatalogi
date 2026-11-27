
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A virtual screen reader walks the officer flow (officer-flow-accessibility, REQ-OFA-003).
 *
 * `@guidepup/virtual-screen-reader` runs inside the page over `document.body`.
 * The walk is the keyboard walk of REQ-OFA-002 (officer-keyboard.spec.ts):
 * create a publication with a title and a Woo category, attach a document,
 * publish it and withdraw it with a reason. The reviewer's step (submit,
 * approve) goes through the lifecycle API, as in the keyboard walk.
 *
 * Tab moves through the virtual screen reader, so every stop is spoken and
 * logged. Enter is a real key press, because a file chooser opens only on a
 * trusted event. For every control the walk uses, the spoken phrase must carry
 * a role and the control's name. After Publish and after Withdraw, something
 * must announce the outcome (a live region or an alert), not only the button.
 * The whole spoken log is attached to the run as `screen-reader-transcript`.
 *
 * Written from the change's scenario; not yet run against an instance. It is
 * expected to fail on today's code until task 3.2 adds the missing names and
 * the live regions for the publish and withdraw outcomes.
 *
 * @e2e openspec/changes/officer-flow-accessibility/specs/officer-accessibility/spec.md#requirement-a-screen-reader-pass-is-run-and-its-result-published-req-ofa-003 REQ-OFA-003
 */
import type { APIRequestContext, Page, TestInfo } from '@playwright/test'

import * as fs from 'fs'
import { expect, test } from './authenticated-request.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const TITLE = `Schermlezerpublicatie ${Date.now()}`
const STEPS = 150

/** The roles a control the officer uses can be announced with. */
const CONTROL_ROLES = ['button', 'textbox', 'combobox', 'menuitem', 'checkbox', 'switch', 'link', 'tab', 'option', 'spinbutton', 'searchbox', 'listbox']

/** The browser build of the virtual screen reader, read once from node_modules. */
const READER_SOURCE = fs.readFileSync(require.resolve('@guidepup/virtual-screen-reader/browser.js'), 'utf8')

/** The spoken log of the whole walk, for the transcript artefact. */
const transcript: string[] = []

/**
 * Start the virtual screen reader in the page, over the whole body so dialogs
 * (mounted outside the app's root) are read too. Imported from a blob, which
 * the page's CSP allows only because this file sets `bypassCSP`.
 */
async function startReader(page: Page): Promise<void> {
	await page.evaluate(async (source) => {
		const holder = window as unknown as { __vsr?: { start: (options: { container: Element }) => Promise<void> } }
		if (holder.__vsr) return
		const url = URL.createObjectURL(new Blob([source], { type: 'text/javascript' }))
		const module = await import(/* @vite-ignore */ url)
		holder.__vsr = module.virtual
		await module.virtual.start({ container: document.body })
	}, READER_SOURCE)
}

/** Run one method of the reader in the page and answer its result. */
async function reader<T>(page: Page, method: string, argument?: string): Promise<T> {
	return page.evaluate(async ([name, arg]) => {
		const vsr = (window as unknown as { __vsr: Record<string, (value?: string) => Promise<unknown>> }).__vsr
		return (arg === undefined ? vsr[name]() : vsr[name](arg)) as Promise<unknown>
	}, [method, argument] as const) as Promise<T>
}

/** Move with Tab (or Shift+Tab) through the reader and answer what it spoke. */
async function speakNext(page: Page, back = false): Promise<string> {
	await reader(page, 'press', back ? 'Shift+Tab' : 'Tab')
	await page.waitForTimeout(60)
	return reader<string>(page, 'lastSpokenPhrase')
}

/** Whether a spoken phrase names a control by role and by `name`. */
function namesControl(phrase: string, name: string): boolean {
	const lower = phrase.toLowerCase()
	return CONTROL_ROLES.some((role) => lower.includes(role)) && lower.includes(name.toLowerCase())
}

/**
 * Tab until the reader speaks `name`, then require that the phrase carries a
 * role as well as the name. A control that is reached but announced without
 * a name never matches, so the walk fails on it by name.
 */
async function tabTo(page: Page, name: string, back = false): Promise<void> {
	const heard: string[] = []
	for (let step = 0; step < STEPS; step++) {
		const phrase = await speakNext(page, back)
		heard.push(phrase)
		if (phrase.toLowerCase().includes(name.toLowerCase())) {
			expect.soft(namesControl(phrase, name), `"${name}" is announced with a role and its name (heard "${phrase}")`).toBe(true)
			return
		}
	}
	throw new Error(`the screen reader never announced "${name}"; the last stops were: ${heard.slice(-8).join(' | ')}`)
}

/** A real key press (trusted); the reader logs what it speaks because of it. */
async function press(page: Page, key: string): Promise<void> {
	await page.keyboard.press(key)
	await page.waitForTimeout(120)
}

/**
 * Move the reader's log into the transcript and clear it, so the phrases
 * after one action can be read alone.
 */
async function clearLog(page: Page): Promise<void> {
	transcript.push(...await reader<string[]>(page, 'spokenPhraseLog'))
	await reader(page, 'clearSpokenPhraseLog')
}

/**
 * Wait until the reader announces an outcome matching `pattern`, other than
 * the focused button's own phrase.
 */
async function expectAnnounced(page: Page, pattern: RegExp, what: string): Promise<void> {
	await expect.poll(async () => {
		const log = await reader<string[]>(page, 'spokenPhraseLog')
		return log.some((phrase) => pattern.test(phrase) && !/^button\b/i.test(phrase.trim()))
	}, { message: `${what} is announced to the screen reader`, timeout: 20_000 }).toBe(true)
}

/** The publication this walk created, through the objects API. */
async function created(request: APIRequestContext): Promise<{ id: string } | null> {
	const res = await request.get(`${OBJECTS}/publication/publication?_search=${encodeURIComponent(TITLE)}&_limit=5`)
	if (!res.ok()) return null
	const rows = ((await res.json()).results ?? []) as Array<Record<string, unknown>>
	const row = rows.find((candidate) => JSON.stringify(candidate.title ?? '').includes(TITLE))
	return row ? { id: String(row.id) } : null
}

/** The publication's public state, through the app's visibility route. */
async function visibility(request: APIRequestContext, id: string | undefined): Promise<string | null> {
	const res = await request.get(`/index.php/apps/opencatalogi/api/publications/${id}/visibility`)
	return res.ok() ? (await res.json()).state : null
}

/** Attach the spoken log to the run as a plain-text artefact. */
async function attachTranscript(page: Page, testInfo: TestInfo): Promise<void> {
	try {
		await clearLog(page)
	} catch {
		// The page may be gone after a failure; keep what was logged so far.
	}
	await testInfo.attach('screen-reader-transcript', { body: transcript.join('\n'), contentType: 'text/plain' })
}

test.describe('A virtual screen reader walks the officer flow (REQ-OFA-003)', () => {
	test.setTimeout(300_000)
	test.use({ bypassCSP: true })

	test.afterAll(async ({ request }) => {
		const publication = await created(request)
		if (publication) await request.delete(`${OBJECTS}/publication/publication/${publication.id}`)
	})

	test('every control used is announced with a role and a name', async ({ page, request }, testInfo) => {
		const catalog = await request.get(`${OBJECTS}/publication/catalog?_limit=1`)
		const slug = String(((await catalog.json()).results ?? [])[0]?.slug ?? '')
		expect(slug, 'the instance holds a catalog').not.toBe('')
		transcript.length = 0

		await page.goto(`${APP}/publications/${slug}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.getByRole('button', { name: 'Add Publication' })).toBeVisible({ timeout: 30_000 })
		await startReader(page)

		try {
			await test.step('create with a title and a category', async () => {
				await tabTo(page, 'Add Publication')
				await press(page, 'Enter')
				for (const [field, text] of [['Title', TITLE], ['Summary', 'Schermlezer.'], ['Description', 'Gemaakt met een schermlezer.']]) {
					await tabTo(page, field)
					await press(page, 'Enter')
					await page.keyboard.type(text)
				}
				await tabTo(page, 'Woo information category')
				await press(page, 'Enter')
				await press(page, 'ArrowDown')
				await press(page, 'Enter')
				await tabTo(page, 'Create')
				await press(page, 'Enter')
				await expect.poll(async () => (await created(request)) !== null, { message: 'the publication is saved' }).toBe(true)
			})

			await test.step('attach a document', async () => {
				page.once('filechooser', (chooser) => chooser.setFiles({ name: 'besluit.txt', mimeType: 'text/plain', buffer: Buffer.from('Besluit') }))
				await tabTo(page, 'Add File', true)
				await press(page, 'Enter')
				await tabTo(page, 'Add a file')
				await press(page, 'Enter')
				await expect(page.getByText('besluit.txt')).toBeVisible({ timeout: 15_000 })
				await tabTo(page, 'Done')
				await press(page, 'Enter')
				await press(page, 'Escape')
			})

			const publication = await created(request)
			expect(publication).not.toBeNull()
			await test.step('a reviewer approves (lifecycle API)', async () => {
				for (const action of ['submit', 'approve']) {
					const res = await request.post(`${OBJECTS}/${publication?.id}/transition`, { data: { action, data: {} } })
					expect(res.ok(), `${action} answers 2xx`).toBe(true)
				}
			})

			await test.step('publish, and hear that it is published', async () => {
				await tabTo(page, TITLE)
				await press(page, 'Enter')
				await page.waitForURL(new RegExp(String(publication?.id)))
				await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible({ timeout: 30_000 })
				await tabTo(page, 'Actions')
				await press(page, 'Enter')
				await press(page, 'ArrowDown')
				await tabTo(page, 'Publication Date')
				await page.keyboard.type(new Date(Date.now() - 60_000).toISOString())
				await tabTo(page, 'Publish')
				await clearLog(page)
				await press(page, 'Enter')
				await expect.poll(() => visibility(request, publication?.id), { message: 'the publication is public', timeout: 20_000 }).toBe('public')
				await expectAnnounced(page, /published|gepubliceerd/i, 'the publish outcome')
			})

			await test.step('withdraw with a reason, and hear that it is withdrawn', async () => {
				await tabTo(page, 'Withdraw')
				await press(page, 'Enter')
				await tabTo(page, 'Reason')
				await page.keyboard.type('Met het verkeerde besluit gepubliceerd.')
				await tabTo(page, 'Withdraw')
				await clearLog(page)
				await press(page, 'Enter')
				await expect.poll(() => visibility(request, publication?.id), { message: 'the publication ends withdrawn', timeout: 20_000 }).toBe('withdrawn')
				await expectAnnounced(page, /withdrawn|ingetrokken/i, 'the withdraw outcome')
			})
		} finally {
			await attachTranscript(page, testInfo)
		}
	})
})
