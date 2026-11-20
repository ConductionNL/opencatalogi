/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Organizations pages edit OpenRegister's shared organisation
 * (organisations-page-edits-the-shared-organisation, REQ-SHO-103): its
 * administrator enters the RSIN on the detail page and it is stored on the
 * shared organisation; a member who is not its administrator reads the
 * identifiers and is offered no edit.
 *
 * THE FIXTURE. One organisation created through OpenRegister's organisation
 * API by the suite's account, which makes that account its administrator, and
 * one non-admin account that joins it as a plain member. Both are removed
 * afterwards. The RSIN is a valid one (passes the 11-proef).
 *
 * Base URL and the admin session come from the environment and
 * playwright.config.ts; the member signs in on a context with an empty
 * storage state. Texts are matched in English and Dutch.
 *
 * @e2e openspec/changes/organisations-page-edits-the-shared-organisation/specs/shared-organisation/spec.md#scenario-an-administrator-enters-the-rsin
 * @e2e openspec/changes/organisations-page-edits-the-shared-organisation/specs/shared-organisation/spec.md#scenario-another-user-reads-but-cannot-edit
 */
import { expect, test } from './authenticated-request.ts'
import { dismissOverlays } from './spec-coverage/_nav.ts'
import { dialog, fillField } from './workflows/_crud.ts'

const APP = '/index.php/apps/opencatalogi'
const OR_API = '/index.php/apps/openregister/api'
const PROJECTION = `${OR_API}/objects/directory/nc-organisation`
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const RSIN = '002220647'
const RUN = `sho-${Date.now()}`
const NAME = `Gemeente Zuiddrecht ${RUN}`
const MEMBER = { id: `${RUN}-member`, password: 'Sho-member-2026-e2e!' }

test.describe('Organizations: the shared organisation', () => {
	let uuid = ''

	test.beforeAll(async ({ request: admin, playwright, baseURL }) => {
		const created = await admin.post(`${OR_API}/organisations`, {
			data: { name: NAME, description: 'E2E organisation without an RSIN' },
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		const organisation = body.organisation ?? body
		uuid = String(organisation.uuid ?? organisation.id)
		expect(uuid, 'the organisation has a uuid').not.toBe('')

		const user = await admin.post('/ocs/v2.php/cloud/users', {
			headers: OCS,
			data: { userid: MEMBER.id, password: MEMBER.password },
		})
		expect(user.ok(), await user.text()).toBe(true)

		const member = await playwright.request.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: MEMBER.id, password: MEMBER.password },
		})
		const joined = await member.post(`${OR_API}/organisations/${uuid}/join`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(joined.ok(), `the member joins: ${await joined.text()}`).toBe(true)
		await member.dispose()
	})

	test.afterAll(async ({ request: admin }) => {
		await admin.delete(`/ocs/v2.php/cloud/users/${MEMBER.id}`, { headers: OCS })
		if (uuid !== '') {
			// The projection refuses delete (an organisation is merged, not
			// deleted); the organisation API is the fixture's own way out.
			await admin.delete(`${OR_API}/organisations/${uuid}`)
		}
	})

	test('an administrator enters the RSIN', async ({ page, request: admin }) => {
		const before = await (await admin.get(`${PROJECTION}/${uuid}`)).json()
		expect(before.rsin ?? null, 'the fixture starts without an RSIN').toBe(null)

		await page.goto(`${APP}/organizations/${uuid}`)
		await dismissOverlays(page)
		await expect(page.getByText(NAME).first()).toBeVisible({ timeout: 20000 })

		const edit = page.locator('[data-testid="cn-detail-page-edit"]').first()
		await expect(edit).toBeVisible({ timeout: 20000 })
		await edit.click()
		const form = dialog(page)
		await expect(form).toBeVisible({ timeout: 10000 })
		await fillField(form, 'RSIN', RSIN)
		await form.getByRole('button', { name: /^(Save|Opslaan)$/ }).first().click()
		await expect(form).toBeHidden({ timeout: 15000 })

		const after = await (await admin.get(`${PROJECTION}/${uuid}`)).json()
		expect(after.rsin).toBe(RSIN)
		await expect(page.getByText(RSIN).first()).toBeVisible({ timeout: 15000 })
	})

	test('another user reads but cannot edit', async ({ baseURL, browser }) => {
		const context = await browser.newContext({
			baseURL,
			storageState: { cookies: [], origins: [] },
		})
		const page = await context.newPage()
		await page.goto('/index.php/login')
		await page.fill('#user', MEMBER.id)
		await page.fill('#password', MEMBER.password)
		await page.press('#password', 'Enter')
		await page.waitForURL((url) => !url.pathname.includes('/login'))

		await page.goto(`${APP}/organizations/${uuid}`)
		await dismissOverlays(page)
		// The record itself must have rendered, or "no edit" proves nothing.
		await expect(page.getByText(NAME).first()).toBeVisible({ timeout: 20000 })
		await expect(page.getByText(/RSIN/).first()).toBeVisible()

		await expect(page.locator('[data-testid="cn-detail-page-edit"]')).toHaveCount(0)
		await expect(
			page.getByRole('button', { name: /^(Edit|Bewerken)$/ }),
		).toHaveCount(0)
		await context.close()
	})
})
