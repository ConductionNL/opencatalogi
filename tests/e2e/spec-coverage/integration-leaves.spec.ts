/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Integration leaves: mail intake makes a draft, the calendar leaf plans
 * without publishing, and the organization leaves link without copying.
 *
 * WHAT THIS SPEC IS LOOKING FOR.
 *
 * 1. Mail intake. The publication schema carries a `mailObjectTemplate` of
 *    exactly `title`, `summary` and `description`. The object the Mail
 *    sidebar's "New publication from this mail" button writes (OpenRegister's
 *    `ActionsTab.submitCreate()` expands the template and posts it to the
 *    objects URL) is a publication with the subject as title, the preview as
 *    summary and a provenance description naming the sender, and with no
 *    `publicationDate`. An anonymous reader gets nothing for it from the public
 *    publications API, the catalogue's sitemap or its DCAT feed. The button
 *    itself is OpenRegister's and is covered by its MailSidebar tests; this file
 *    writes what the button writes, because a browser test cannot rely on a
 *    mailbox holding a message on the instance under test.
 * 2. Planning. A "Publish Friday" event linked to an unpublished publication
 *    through the calendar leaf shows on PublicationDetail in the card
 *    "Planning (does not publish)", and the publication still has no
 *    `publicationDate` and is still not public.
 * 3. Organization leaves. OrganizationDetail carries a forms widget and a
 *    contacts widget; a form and a contact card linked through them show in
 *    their widgets, and the organization's own properties are unchanged.
 *
 * A DISABLED LEAF APP. When Calendar, Forms or Contacts is not enabled, the
 * provider's `isEnabled()` is false and the widget renders as absent (the
 * `cn-integration-widget-absent` placeholder) or not at all. The test then
 * asserts the page still renders without that widget and skips the linking
 * step, saying which app is off.
 *
 * DEPENDS ON. `leaf-integrations` tasks 1 and 2: `linkedTypes` and
 * `mailObjectTemplate` on `publication` (built), the `pub-calendar` widget
 * (built), and `linkedTypes: ["forms", "contacts"]` with the `org-intake-form`
 * and `org-contacts` widgets on the organization (still open: the organization
 * schema is now OpenRegister's shared one). OpenRegister's linked-entity route
 * `POST /api/objects/{uuid}/_linked/{type}` and its calendar, forms and contacts
 * providers. Until the organization half is on the instance the third test
 * fails at its widget assertion; it is written now from the change's scenarios
 * so the build that adds it has its acceptance test waiting.
 *
 * WHAT IT LEAVES BEHIND. Nothing: the publications, the catalogue, the
 * calendar, the contact card, the form and the organisation membership are
 * removed in afterAll.
 *
 * @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#scenario-an-emailed-publication-request-becomes-a-draft-not-a-publication
 * @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#scenario-an-editor-plans-a-publish-date-without-publishing
 * @e2e openspec/changes/leaf-integrations/specs/integration-leaves/spec.md#scenario-a-desk-links-the-intake-form-and-a-contact-person
 * @spec openspec/changes/leaf-integrations/tasks.md#task-3
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '../authenticated-request.ts'
import { BASE, Fixtures, newRunId, REG_PUBLICATION, SCHEMA_PUBLICATION } from '../workflows/_fixtures.ts'
import { APP, dismissOverlays } from './_nav.ts'

/** OpenRegister's API base path. */
const OR_API = '/index.php/apps/openregister/api'

/** The app's API base path. */
const APP_API = `${APP}/api`

/** Headers for OCS calls. */
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/** The calendar widget's title (task 2). */
const PLANNING = 'Planning (does not publish)'

/** What a Woo sitemap is read for; a draft must be in none of them. */
const SITEMAP_CATEGORY = 'infocat014'

/**
 * The signed-in user's id.
 *
 * @param request The signed-in request context.
 * @return The uid.
 */
async function whoami(request: APIRequestContext): Promise<string> {
	const res = await request.get('/ocs/v2.php/cloud/user?format=json', { headers: OCS })
	expect(res.ok(), await res.text()).toBe(true)
	return String((await res.json())?.ocs?.data?.id ?? '')
}

/**
 * The apps enabled on the instance.
 *
 * @param request The signed-in admin request context.
 * @return The enabled app ids.
 */
async function enabledApps(request: APIRequestContext): Promise<string[]> {
	const res = await request.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', { headers: OCS })
	expect(res.ok(), `listing enabled apps -> ${res.status()}`).toBe(true)
	return ((await res.json())?.ocs?.data?.apps ?? []) as string[]
}

/**
 * A detail card by its title.
 *
 * @param page The page.
 * @param title The card's title.
 * @return The card.
 */
function cardTitled(page: Page, title: string | RegExp): Locator {
	return page.locator('.cn-detail-card').filter({ has: page.getByRole('heading', { name: title }) })
}

/**
 * Assert a leaf whose app is disabled is absent rather than broken: either no
 * card at all, or the card holds the absent placeholder.
 *
 * @param page The page.
 * @param title The widget's title.
 */
async function expectAbsentLeaf(page: Page, title: string | RegExp): Promise<void> {
	const card = cardTitled(page, title)
	if (await card.count() > 0) {
		await expect(card.getByTestId('cn-integration-widget-absent').first()).toBeVisible()
	}
}

/**
 * Link an entity of a leaf to an object through OpenRegister.
 *
 * @param request The signed-in request context.
 * @param uuid The object's uuid.
 * @param type The linked type.
 * @param entityId The entity's id as the provider names it.
 */
async function link(request: APIRequestContext, uuid: string, type: string, entityId: string): Promise<void> {
	const res = await request.post(`${OR_API}/objects/${uuid}/_linked/${type}`, { data: { id: entityId } })
	expect(res.ok(), `linking ${type} ${entityId}: ${res.status()} ${await res.text()}`).toBe(true)
}

/**
 * Assert an anonymous reader gets nothing for a publication: not from the
 * public publications API, the sitemap or the DCAT feed.
 *
 * @param anonymous A request context with no session.
 * @param slug The catalogue's slug.
 * @param id The publication's id.
 */
async function expectNotPublic(anonymous: APIRequestContext, slug: string, id: string): Promise<void> {
	const shown = await anonymous.get(`${APP_API}/${slug}/${id}`)
	expect(shown.ok(), `the public API returns no ${id}`).toBe(false)

	const listed = await anonymous.get(`${APP_API}/${slug}`, { params: { _limit: '100' } })
	if (listed.ok()) {
		expect(await listed.text(), 'the public list does not name the draft').not.toContain(id)
	}

	const sitemap = await anonymous.get(`${APP_API}/${slug}/sitemaps/${SITEMAP_CATEGORY}/publications`)
	expect(await sitemap.text(), 'the sitemap does not name the draft').not.toContain(id)

	const dcat = await anonymous.get(`${APP_API}/catalogi/publications/dcat`)
	expect(await dcat.text(), 'the DCAT feed does not name the draft').not.toContain(id)
}

test.describe('integration-leaves', () => {
	test.setTimeout(120_000)

	const fx = new Fixtures()
	const run = newRunId()
	let uid = ''
	let slug = ''
	const calendar = `e2e-leaves-${run}`
	const contactUid = `e2e-leaves-${run}`
	let formId = ''
	let organisation = ''
	const mailDrafts: string[] = []

	test.beforeAll(async ({ request }) => {
		await fx.init()
		uid = await whoami(request)
		slug = `${fx.prefix}-leaves`
		await fx.createCatalog('Leaves', { slug })
	})

	test.afterAll(async ({ request }) => {
		for (const id of mailDrafts) {
			await request.delete(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}/${id}`).catch(() => undefined)
		}
		await request.fetch(`/remote.php/dav/calendars/${uid}/${calendar}`, { method: 'DELETE' }).catch(() => undefined)
		await request.fetch(`/remote.php/dav/addressbooks/users/${uid}/contacts/${contactUid}.vcf`, { method: 'DELETE' }).catch(() => undefined)
		if (formId !== '') {
			await request.delete(`/ocs/v2.php/apps/forms/api/v3/forms/${formId}`, { headers: OCS }).catch(() => undefined)
		}
		if (organisation !== '') {
			await request.post(`${OR_API}/organisations/${organisation}/leave`, { data: {} }).catch(() => undefined)
		}
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('REQ-002: an emailed publication request becomes a draft, not a publication', async ({ playwright, request }) => {
		const schema = await request.get(`${OR_API}/schemas/${SCHEMA_PUBLICATION}`)
		expect(schema.ok(), `GET publication schema -> ${schema.status()}`).toBe(true)
		const template = ((await schema.json()).configuration?.mailObjectTemplate ?? null) as Record<string, string> | null
		expect(template, 'the publication schema declares a mailObjectTemplate').not.toBeNull()
		expect(Object.keys(template ?? {}).sort()).toEqual(['description', 'summary', 'title'])

		// Expand the template as the Mail sidebar does for one message.
		const mail: Record<string, string> = {
			subject: `${fx.prefix} Verzoek om publicatie van het raadsbesluit`,
			preview: 'Graag zien wij het raadsbesluit van 3 maart gepubliceerd.',
			senderName: 'Jan de Vries',
			sender: 'jan.devries@example.nl',
			date: '2026-11-27',
		}
		const expand = (value: string): string => value.replace(/\{\{\s*(\w+)\s*\}\}/g, (whole, key) => mail[key] ?? whole)
		const data = Object.fromEntries(Object.entries(template ?? {}).map(([key, value]) => [key, expand(value)]))
		const created = await request.post(`${OR_API}/objects/${REG_PUBLICATION}/${SCHEMA_PUBLICATION}`, { data })
		expect(created.ok(), `creating from the template: ${created.status()} ${await created.text()}`).toBe(true)
		const body = await created.json()
		const id = String(body.id ?? body['@self']?.id)
		mailDrafts.push(id)

		const stored = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, id)
		expect(stored?.title).toBe(mail.subject)
		expect(stored?.summary).toBe(mail.preview)
		expect(String(stored?.description)).toContain(mail.sender)
		expect(String(stored?.description)).toContain(mail.senderName)
		expect(stored?.publicationDate ?? null).toBeNull()

		const anonymous = await playwright.request.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: { Accept: 'application/json' },
		})
		try {
			await expectNotPublic(anonymous, slug, id)
		} finally {
			await anonymous.dispose()
		}
	})

	test('REQ-003: an editor plans a publish date without publishing', async ({ page, playwright, request }) => {
		const publication = await fx.createPublication('planned')
		const calendarEnabled = (await enabledApps(request)).includes('calendar')

		await page.goto(`${APP}/publications/${slug}/${publication.id}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.getByText(publication.title).first()).toBeVisible({ timeout: 30_000 })

		if (!calendarEnabled) {
			await expectAbsentLeaf(page, PLANNING)
			test.skip(true, 'the Calendar app is not enabled, so the planning leaf is absent and nothing can be linked')
			return
		}

		// A "Publish Friday" event in a calendar of this run.
		const made = await request.fetch(`/remote.php/dav/calendars/${uid}/${calendar}`, { method: 'MKCALENDAR' })
		expect(made.status(), `making the calendar -> ${made.status()}`).toBeLessThan(300)
		const eventUid = `e2e-publish-friday-${run}`
		const ics = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//OpenCatalogi//e2e//EN',
			'BEGIN:VEVENT',
			`UID:${eventUid}`,
			'DTSTAMP:20261127T090000Z',
			'DTSTART:20261204T090000Z',
			'DTEND:20261204T093000Z',
			'SUMMARY:Publish Friday',
			'END:VEVENT',
			'END:VCALENDAR',
			'',
		].join('\r\n')
		const put = await request.put(`/remote.php/dav/calendars/${uid}/${calendar}/${eventUid}.ics`, {
			data: ics,
			headers: { 'Content-Type': 'text/calendar; charset=utf-8' },
		})
		expect(put.status(), `writing the event -> ${put.status()}`).toBeLessThan(300)
		await link(request, publication.id, 'calendar', eventUid)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		const planning = cardTitled(page, PLANNING)
		await expect(planning).toBeVisible({ timeout: 30_000 })
		await expect(planning).toContainText('Publish Friday')

		const after = await fx.fetch(REG_PUBLICATION, SCHEMA_PUBLICATION, publication.id)
		expect(after?.publicationDate ?? null, 'linking an event set no publicationDate').toBeNull()
		expect(after?.depublicationDate ?? null).toBeNull()

		const anonymous = await playwright.request.newContext({
			baseURL: BASE,
			storageState: { cookies: [], origins: [] },
			extraHTTPHeaders: { Accept: 'application/json' },
		})
		try {
			await expectNotPublic(anonymous, slug, publication.id)
		} finally {
			await anonymous.dispose()
		}
	})

	test('REQ-004: a desk links the intake form and a contact person', async ({ page, request }) => {
		const apps = await enabledApps(request)
		const created = await request.post(`${OR_API}/organisations`, {
			data: { name: `${fx.prefix} Gemeente Zuiddrecht`, description: 'E2E organisation for the leaves' },
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		organisation = String((body.organisation ?? body).uuid ?? (body.organisation ?? body).id)
		const before = await (await request.get(`${OR_API}/organisations/${organisation}`)).json()

		await page.goto(`${APP}/organizations/${organisation}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await expect(page.getByText(`${fx.prefix} Gemeente Zuiddrecht`).first()).toBeVisible({ timeout: 30_000 })

		const forms = /intake form/i
		const contacts = /contact/i
		const linked: string[] = []

		if (apps.includes('forms')) {
			const form = await request.post('/ocs/v2.php/apps/forms/api/v3/forms', { headers: OCS, data: {} })
			expect(form.ok(), `making the form -> ${form.status()}`).toBe(true)
			formId = String((await form.json())?.ocs?.data?.id ?? '')
			const title = `${fx.prefix} Publicatieverzoek`
			await request.patch(`/ocs/v2.php/apps/forms/api/v3/forms/${formId}`, {
				headers: OCS,
				data: { keyValuePairs: { title } },
			})
			await link(request, organisation, 'forms', formId)
			linked.push('forms')
		} else {
			await expectAbsentLeaf(page, forms)
		}

		if (apps.includes('contacts')) {
			const vcard = [
				'BEGIN:VCARD',
				'VERSION:3.0',
				`UID:${contactUid}`,
				'FN:Petra Jansen',
				'N:Jansen;Petra;;;',
				'EMAIL:petra.jansen@example.nl',
				'END:VCARD',
				'',
			].join('\r\n')
			const put = await request.put(`/remote.php/dav/addressbooks/users/${uid}/contacts/${contactUid}.vcf`, {
				data: vcard,
				headers: { 'Content-Type': 'text/vcard; charset=utf-8' },
			})
			expect(put.status(), `writing the contact card -> ${put.status()}`).toBeLessThan(300)
			await link(request, organisation, 'contacts', contactUid)
			linked.push('contacts')
		} else {
			await expectAbsentLeaf(page, contacts)
		}

		test.skip(linked.length === 0, 'neither Forms nor Contacts is enabled, so both organization leaves are absent')

		await page.reload({ waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		if (linked.includes('forms')) {
			await expect(cardTitled(page, forms)).toContainText(`${fx.prefix} Publicatieverzoek`, { timeout: 30_000 })
		}
		if (linked.includes('contacts')) {
			await expect(cardTitled(page, contacts)).toContainText('Petra Jansen', { timeout: 30_000 })
		}

		// Neither linking act wrote to the organization's own properties.
		const after = await (await request.get(`${OR_API}/organisations/${organisation}`)).json()
		for (const key of ['name', 'description', 'oin', 'tooi', 'rsin']) {
			expect((after.organisation ?? after)[key] ?? null, `${key} is unchanged`).toEqual((before.organisation ?? before)[key] ?? null)
		}
	})
})
