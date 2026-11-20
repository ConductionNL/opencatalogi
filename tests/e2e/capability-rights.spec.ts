/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Capabilities are rights granted per group (REQ-WAB-004, task 4.3).
 *
 *   @e2e woo-compliance::a-group-may-open-inspection-periods-and-nothing-else
 *
 * The administrator grants `openInspection` to the group `ter-inzage` in the
 * rights table of the OpenCatalogi admin settings. A user in that group, who
 * is not an administrator and works in a context with no stored session,
 * then opens an inspection period, and is refused with a 403 naming
 * `publishWooBatch` when trying to publish a Woo batch.
 *
 * The grant `openInspection` had before the run is put back afterwards, and
 * the run's user is removed.
 *
 * @spec openspec/changes/woo-access-boundary-hardening/specs/woo-compliance/spec.md#requirement-capabilities-are-rights-granted-per-group-req-wab-004
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { BASE_URL } from './base-url.ts'

const RUN = Date.now().toString(36)
const GROUP = 'ter-inzage'
const RIGHTS = '/index.php/apps/opencatalogi/api/settings/capability-rights'
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const OFFICER = { id: `e2e-wab-${RUN}`, password: `Wab-${RUN}-Inzage-42!` }

/**
 * An API context acting as one account, with no stored session.
 *
 * @param username The account.
 * @param password Its password.
 */
async function as(username: string, password: string): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username, password },
		extraHTTPHeaders: OCS,
	})
}

/**
 * The groups a right is granted to, read as admin.
 *
 * @param admin The admin context.
 * @param verb The right.
 */
async function grantOf(admin: APIRequestContext, verb: string): Promise<string[]> {
	const response = await admin.get(RIGHTS)
	expect(response.ok(), `reading the rights: ${response.status()}`).toBeTruthy()
	const row = ((await response.json()).rights as Array<{ verb: string, groups: string[] }>).find((r) => r.verb === verb)
	expect(row, `the right ${verb} is listed`).toBeTruthy()
	return row!.groups
}

test('a group may open inspection periods and nothing else', async ({ page }) => {
	test.setTimeout(180_000)
	const admin = await as(
		process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
		process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
	)
	const before = await grantOf(admin, 'openInspection')
	const batchBefore = await grantOf(admin, 'publishWooBatch')
	try {
		// The group may already exist; creating it again is refused and harmless.
		await admin.post('/ocs/v2.php/cloud/groups', { form: { groupid: GROUP } })
		const made = await admin.post('/ocs/v2.php/cloud/users', { form: { userid: OFFICER.id, password: OFFICER.password } })
		expect(made.ok(), `creating ${OFFICER.id}: ${made.status()}`).toBeTruthy()
		const joined = await admin.post(`/ocs/v2.php/cloud/users/${OFFICER.id}/groups`, { form: { groupid: GROUP } })
		expect(joined.ok(), `adding ${OFFICER.id} to ${GROUP}: ${joined.status()}`).toBeTruthy()
		// The scenario's group holds this one right only.
		expect(batchBefore, `${GROUP} must not already hold publishWooBatch`).not.toContain(GROUP)

		// The administrator grants the right in the rights table.
		await page.goto('/index.php/settings/admin/opencatalogi')
		const table = page.getByTestId('capability-rights')
		await expect(table).toBeVisible({ timeout: 30_000 })
		if (!before.includes(GROUP)) {
			const saved = page.waitForResponse((r) => r.url().includes('/capability-rights/openInspection') && r.request().method() === 'PUT')
			const picker = page.locator('#capability-right-openInspection')
			await picker.click()
			await picker.fill(GROUP)
			await page.getByRole('option', { name: GROUP, exact: true }).click()
			expect((await saved).ok(), 'saving the grant').toBeTruthy()
		}
		expect(await grantOf(admin, 'openInspection')).toContain(GROUP)

		// The officer, who is not an administrator, may open an inspection period…
		const officer = await as(OFFICER.id, OFFICER.password)
		const opened = await officer.post('/index.php/apps/opencatalogi/api/inspections', {
			data: {
				record: { id: `e2e-wab-record-${RUN}` },
				recordType: { slug: 'omgevingsvergunning', inspectionTermDays: 42 },
				documents: [`e2e-wab-doc-${RUN}`],
			},
		})
		expect(opened.status(), `opening an inspection: ${(await opened.text()).slice(0, 300)}`).not.toBe(403)
		expect(opened.ok(), `opening an inspection: ${opened.status()}`).toBeTruthy()

		// …and nothing else: publishing a Woo batch is refused, naming the right.
		const published = await officer.post(`/index.php/apps/opencatalogi/api/woo/batches/e2e-wab-${RUN}/publish`, { data: {} })
		expect(published.status()).toBe(403)
		expect((await published.json()).right).toBe('publishWooBatch')
		await officer.dispose()
	} finally {
		await admin.put(`${RIGHTS}/openInspection`, { data: { groups: before } })
		await admin.delete(`/ocs/v2.php/cloud/users/${OFFICER.id}`).catch(() => undefined)
		await admin.dispose()
	}
})
