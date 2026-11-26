/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publications-reference-the-shared-organisation REQ-SHO-102: the
 * organisation a publication names is the one its rights follow.
 *
 * WHAT THIS SPEC IS LOOKING FOR:
 * - An officer who belongs to two organisations, with the first active,
 *   creates a draft that names the second. The stored publication's
 *   `@self.organisation` is the second, not the officer's active one
 *   (`x-openregister-organisation: {fromProperty: "organization"}`).
 * - A colleague who belongs only to the first organisation does not see that
 *   draft in the publications list, nor read it by id.
 * - The organisation picker on the publication form offers the officer the two
 *   organisations the officer belongs to and not a third one (task 6.2).
 *
 * THE FIXTURE. Three organisations made through OpenRegister's organisation
 * API by the suite's account ("Gemeente Voorbeeld", "Omgevingsdienst
 * Voorbeeld" and an unrelated third), two non-admin accounts that join them
 * (the officer joins the first two, the colleague the first only) and one
 * catalogue on the publication pair. The accounts and the draft are removed
 * afterwards; OpenRegister deletes no organisation (405), so the suite's
 * account leaves the three instead. Texts are matched in English and Dutch.
 *
 * DEPENDS ON. OpenRegister's `object-organisation-from-a-property` (the
 * annotation is dropped on import before it) and task 6.1, which declares it
 * on `#publication`. Until both are on the instance the first assertion fails:
 * the draft lands in the officer's active organisation. Written from the
 * change's scenario so the build that adds them has its acceptance test
 * waiting; not run in the cloud sandbox.
 *
 * @e2e openspec/changes/publications-reference-the-shared-organisation/specs/shared-organisation/spec.md#scenario-naming-the-unit-is-what-scopes-the-rights REQ-SHO-102
 * @spec openspec/changes/publications-reference-the-shared-organisation/tasks.md#task-6.2
 */
import type { APIRequestContext, Playwright } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog } from './publication-catalog.ts'
import { dismissOverlays } from './spec-coverage/_nav.ts'
import { dialog } from './workflows/_crud.ts'

const APP = '/index.php/apps/opencatalogi'
const OR_API = '/index.php/apps/openregister/api'
const PUBLICATIONS = `${OR_API}/objects/publication/publication`
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const RUN = `sho102-${Date.now().toString(36)}`
const MUNICIPALITY = `Gemeente Voorbeeld ${RUN}`
const AGENCY = `Omgevingsdienst Voorbeeld ${RUN}`
const UNRELATED = `Waterschap Elders ${RUN}`
const OFFICER = { id: `${RUN}-officer`, password: 'Sho102-officer-2026-e2e!' }
const COLLEAGUE = { id: `${RUN}-colleague`, password: 'Sho102-colleague-2026-e2e!' }
const TITLE = `E2E draft of the agency ${RUN}`
const ORGANISATION_LABEL = /^(Organisation|Organization|Organisatie)$/

/**
 * A request context signed in as one of the fixture's accounts.
 *
 * @param playwright The Playwright instance.
 * @param baseURL The instance under test.
 * @param account The account.
 * @param account.id The user id.
 * @param account.password The password.
 * @return The context. The caller disposes it.
 */
async function signedInAs(
	playwright: Playwright,
	baseURL: string,
	account: { id: string, password: string },
): Promise<APIRequestContext> {
	return await playwright.request.newContext({
		baseURL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: account.id, password: account.password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
}

/**
 * The uuid an organisation answer names.
 *
 * @param body The organisation API's answer.
 * @return The uuid.
 */
function uuidOf(body: Record<string, unknown>): string {
	const record = (body.organisation ?? body) as Record<string, unknown>
	return String(record.uuid ?? record.id ?? '')
}

/**
 * The organisation a stored publication's `@self` holds, as a uuid.
 *
 * @param publication The publication as OpenRegister answers it.
 * @return The uuid, or '' when there is none.
 */
function ownerOf(publication: Record<string, unknown>): string {
	const self = (publication['@self'] ?? {}) as Record<string, unknown>
	const owner = self.organisation
	if (owner !== null && typeof owner === 'object') {
		return String((owner as Record<string, unknown>).uuid ?? (owner as Record<string, unknown>).id ?? '')
	}
	return String(owner ?? '')
}

test.describe('Shared organisation: naming the unit scopes the rights', () => {
	test.describe.configure({ mode: 'serial' })

	const organisations: Record<string, string> = {}
	let catalogSlug = ''
	let publicationId = ''

	test.beforeAll(async ({ request: admin, playwright, baseURL }) => {
		for (const name of [MUNICIPALITY, AGENCY, UNRELATED]) {
			const created = await admin.post(`${OR_API}/organisations`, {
				data: { name, description: `E2E ${RUN}` },
			})
			expect(created.ok(), await created.text()).toBe(true)
			organisations[name] = uuidOf(await created.json())
			expect(organisations[name], `${name} has a uuid`).not.toBe('')
		}

		for (const account of [OFFICER, COLLEAGUE]) {
			const user = await admin.post('/ocs/v2.php/cloud/users', {
				headers: OCS,
				data: { userid: account.id, password: account.password },
			})
			expect(user.ok(), await user.text()).toBe(true)
		}

		const memberships: Array<[typeof OFFICER, string[]]> = [
			[OFFICER, [MUNICIPALITY, AGENCY]],
			[COLLEAGUE, [MUNICIPALITY]],
		]
		for (const [account, names] of memberships) {
			const context = await signedInAs(playwright, baseURL as string, account)
			for (const name of names) {
				const joined = await context.post(`${OR_API}/organisations/${organisations[name]}/join`)
				expect(joined.ok(), `${account.id} joins ${name}: ${await joined.text()}`).toBe(true)
			}
			// The municipality is the active organisation of both.
			const active = await context.post(`${OR_API}/organisations/${organisations[MUNICIPALITY]}/set-active`)
			expect(active.ok(), `${account.id} activates ${MUNICIPALITY}: ${await active.text()}`).toBe(true)
			await context.dispose()
		}

		catalogSlug = (await publicationCatalog(admin, RUN)).slug
	})

	test.afterAll(async ({ request: admin }) => {
		if (publicationId !== '') {
			await admin.delete(`${PUBLICATIONS}/${publicationId}`).catch(() => undefined)
		}
		for (const account of [OFFICER, COLLEAGUE]) {
			await admin.delete(`/ocs/v2.php/cloud/users/${account.id}`, { headers: OCS }).catch(() => undefined)
		}
		// Left a member of these, the suite's account belongs to several
		// organisations and later publications in the run get no organisation.
		for (const uuid of Object.values(organisations)) {
			await admin.post(`${OR_API}/organisations/${uuid}/leave`, { data: {} }).catch(() => undefined)
		}
	})

	test('a draft that names the agency belongs to the agency', async ({ playwright, baseURL }) => {
		const officer = await signedInAs(playwright, baseURL as string, OFFICER)
		try {
			const created = await officer.post(PUBLICATIONS, {
				data: {
					title: TITLE,
					summary: 'A draft the agency publishes, made while the municipality is active.',
					organization: organisations[AGENCY],
				},
			})
			expect(created.ok(), await created.text()).toBe(true)
			const body = await created.json()
			publicationId = String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '')
			expect(publicationId, 'the draft has an id').not.toBe('')

			const stored = await (await officer.get(`${PUBLICATIONS}/${publicationId}`)).json()
			expect(stored.organization, 'the named organisation is stored').toBe(organisations[AGENCY])
			expect(ownerOf(stored), 'the rights follow the named organisation, not the active one').toBe(organisations[AGENCY])
		} finally {
			await officer.dispose()
		}
	})

	test('the colleague of the municipality does not see the draft', async ({ playwright, baseURL }) => {
		expect(publicationId, 'the previous test made the draft').not.toBe('')
		const colleague = await signedInAs(playwright, baseURL as string, COLLEAGUE)
		try {
			const listed = await colleague.get(PUBLICATIONS, { params: { _search: RUN, _limit: 50 } })
			expect(listed.ok(), await listed.text()).toBe(true)
			const results = ((await listed.json()).results ?? []) as Array<Record<string, unknown>>
			const ids = results.map((row) => String(row.id ?? (row['@self'] as Record<string, unknown> | undefined)?.id ?? ''))
			expect(ids, 'the draft is not in the colleague\'s list').not.toContain(publicationId)

			const read = await colleague.get(`${PUBLICATIONS}/${publicationId}`)
			expect(read.ok(), 'the colleague cannot read the draft by id').toBe(false)
		} finally {
			await colleague.dispose()
		}
	})

	test('the officer\'s organisation picker offers the officer\'s organisations only', async ({ baseURL, browser }) => {
		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		try {
			await page.goto('/index.php/login')
			await page.fill('#user', OFFICER.id)
			await page.fill('#password', OFFICER.password)
			await page.press('#password', 'Enter')
			await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 })

			await page.goto(`${APP}/publications/${encodeURIComponent(catalogSlug)}`)
			await dismissOverlays(page)
			await page.getByRole('button', { name: /New publication|Nieuwe publicatie|Add|Toevoegen/ }).first().click()
			const form = dialog(page)
			await expect(form).toBeVisible({ timeout: 15000 })

			const picker = form.getByLabel(ORGANISATION_LABEL).first()
			await picker.click()
			const options = page.locator('.vs__dropdown-option')
			await expect(options.filter({ hasText: MUNICIPALITY }).first()).toBeVisible({ timeout: 10000 })
			await expect(options.filter({ hasText: AGENCY }).first()).toBeVisible()
			await expect(options.filter({ hasText: UNRELATED })).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
