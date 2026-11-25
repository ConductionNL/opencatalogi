/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * data.overheid.nl registration and harvest status (dcat-catalogue-completion task 2.3, REQ-DCC-002).
 *
 * @e2e openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002 REQ-DCC-002
 */
import { expect, test } from './authenticated-request.ts'

/** The admin settings page of the app. */
const SETTINGS_PAGE = '/index.php/settings/admin/opencatalogi'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

test.describe('DCAT catalogue completion: data.overheid.nl', () => {
	/**
	 * DONL registration (task 2.3, REQ-DCC-002): the admin DCAT settings show the
	 * operator's registration and whether data.overheid.nl actually harvests the feed.
	 * Seeded through the real paths: the operator's status and harvester pattern through
	 * the registration API, and a harvester visit as a feed request whose user agent
	 * matches the pattern. The test instance keeps the recorded visit afterwards; the
	 * status and the pattern are restored.
	 *
	 * @e2e openspec/changes/dcat-catalogue-completion/specs/dcat-ap-harvest/spec.md#requirement-the-data-overheid-nl-registration-and-its-harvest-are-tracked-req-dcc-002 REQ-DCC-002
	 */
	test('the admin sees whether data.overheid.nl harvests us', async ({ request: admin, page }) => {
		const registration = `${API_BASE}/dcat/donl-registration`
		const original = await (await admin.get(registration)).json()
		const agent = `e2e-donl-harvester-${Date.now()}`

		try {
			const recorded = await admin.post(registration, {
				data: { status: 'registered', harvesterPattern: agent },
			})
			expect(recorded.ok(), `record: ${await recorded.text()}`).toBe(true)
			expect((await recorded.json())?.status).toBe('registered')

			const feed = await admin.get(`${API_BASE}/dcat`, {
				headers: { 'User-Agent': `${agent}/1.0`, Accept: 'text/turtle' },
			})
			expect(feed.status(), `feed: ${await feed.text()}`).toBeLessThan(500)
			const after = await (await admin.get(registration)).json()
			expect(after?.harvested).toBe(true)
			expect(String(after?.lastFetchAt ?? '')).not.toBe('')

			await page.goto(SETTINGS_PAGE)
			const block = page.getByTestId('donl-harvest-status')
			await block.scrollIntoViewIfNeeded()
			await expect(block.getByTestId('donl-registration-status')).toContainText('Registered on')
			await expect(block.getByTestId('donl-harvest-evidence')).toContainText('Harvested')
		} finally {
			await admin.post(registration, {
				data: {
					status: String(original?.status ?? 'not_requested'),
					harvesterPattern: String(original?.harvesterPattern ?? ''),
				},
			})
		}
	})
})
