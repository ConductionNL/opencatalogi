/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * theme-archive-hotspot REQ-THA-001: an administrator marks a subject as an
 * archive hotspot with a reason; the save stamps when, keeps the subject
 * permanently in OpenRegister's appraisal and writes one audit entry.
 *
 * WHAT THIS SPEC IS LOOKING FOR, through OpenRegister's object API, which the
 * theme form saves through:
 * - Marking without a reason is refused and the subject stays unmarked.
 * - Marking with a reason stores the reason, stamps `archiveHotspotSince`,
 *   sets `@self.retention.archiefnominatie` to `blijvend_bewaren`, and the
 *   audit trail holds one `theme.archiveHotspot.marked` entry with the reason.
 * - Unmarking clears the flag and writes `theme.archiveHotspot.unmarked`.
 *
 * ON THE PAGES. The theme page shows the toggle and the reason to an
 * administrator (task 1.4), and a publication filed under the marked subject
 * says "Kept permanently: archive hotspot <subject>" on its Visibility card
 * and in `GET visibility` (task 3.2, "the officer sees why").
 *
 * WHAT IT CREATES AND REMOVES. One theme, one catalogue and one publication,
 * deleted in afterAll.
 *
 * @e2e openspec/changes/archive/2026-11-25-theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#scenario-an-administrator-marks-a-subject REQ-THA-001
 * @e2e openspec/changes/archive/2026-11-25-theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md#scenario-the-officer-sees-why REQ-THA-003
 * @spec openspec/changes/archive/2026-11-25-theme-archive-hotspot/specs/publication-retention-lifecycle/spec.md
 * @spec openspec/changes/archive/2026-11-25-theme-archive-hotspot/tasks.md#task-1.4
 * @spec openspec/changes/archive/2026-11-25-theme-archive-hotspot/tasks.md#task-3.2
 */

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog } from './publication-catalog.ts'

const APP_BASE = '/index.php/apps/opencatalogi'
const THEMES = '/index.php/apps/openregister/api/objects/publication/theme'
const PUBLICATIONS = '/index.php/apps/openregister/api/objects/publication/publication'
const RUN = Date.now().toString(36)
const REASON = 'Aangewezen in archiefhotspotlijst 2026'

test.describe('Archive hotspot', () => {
	test.describe.configure({ mode: 'serial' })

	let themeId = ''
	let publicationId = ''
	const subject = `Herindeling gemeente ${RUN}`

	test.beforeAll(async ({ request }) => {
		const res = await request.post(THEMES, {
			data: { title: subject, summary: 'A subject for the archive-hotspot check.' },
		})
		expect(res.ok(), `creating the subject: ${await res.text()}`).toBe(true)
		const body = await res.json()
		themeId = String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '')
	})

	test.afterAll(async ({ request }) => {
		if (publicationId !== '') {
			await request.delete(`${PUBLICATIONS}/${publicationId}`).catch(() => undefined)
		}
		if (themeId !== '') {
			await request.delete(`${THEMES}/${themeId}`).catch(() => undefined)
		}
	})

	test('a marking without a reason is refused', async ({ request }) => {
		const res = await request.patch(`${THEMES}/${themeId}`, { data: { archiveHotspot: true } })
		expect(res.ok(), 'no reason, no marking').toBe(false)

		const stored = await (await request.get(`${THEMES}/${themeId}`)).json()
		expect(stored.archiveHotspot ?? false).toBe(false)
	})

	test('an administrator marks a subject', async ({ request }) => {
		const res = await request.patch(`${THEMES}/${themeId}`, {
			data: { archiveHotspot: true, archiveHotspotReason: REASON },
		})
		expect(res.ok(), await res.text()).toBe(true)

		const stored = await (await request.get(`${THEMES}/${themeId}`)).json()
		expect(stored.archiveHotspot).toBe(true)
		expect(stored.archiveHotspotReason).toBe(REASON)
		expect(String(stored.archiveHotspotSince ?? '').slice(0, 10)).toBe(new Date().toISOString().slice(0, 10))
		expect(stored?.['@self']?.retention?.archiefnominatie).toBe('blijvend_bewaren')

		const trail = await request.get(`/index.php/apps/openregister/api/audit-trails?object_uuid=${themeId}`)
		expect(trail.ok(), await trail.text()).toBe(true)
		const entries = ((await trail.json())?.results ?? []) as Array<{ action?: string }>
		const marked = entries.filter((entry) => entry.action === 'theme.archiveHotspot.marked')
		expect(marked, 'one marking entry').toHaveLength(1)
		expect(JSON.stringify(marked[0])).toContain(REASON)
	})

	test('the theme page shows the toggle and the reason to an administrator', async ({ page }) => {
		await page.goto(`${APP_BASE}/themes/${themeId}`)
		await expect(page.getByText('Archive hotspot', { exact: true }).first()).toBeVisible()
		await expect(page.getByText('Reason for the archive hotspot').first()).toBeVisible()
		await expect(page.getByText(REASON).first()).toBeVisible()
	})

	test('the officer sees why', async ({ request, page }) => {
		const catalogSlug = (await publicationCatalog(request, `tha-${RUN}`)).slug
		const res = await request.post(PUBLICATIONS, {
			data: { title: `E2E hotspot ${RUN}`, summary: 'A publication filed under an archive hotspot.', themes: [themeId] },
		})
		expect(res.ok(), await res.text()).toBe(true)
		const body = await res.json()
		publicationId = String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '')

		const visibility = await request.get(`${APP_BASE}/api/publications/${publicationId}/visibility`)
		expect(visibility.ok(), await visibility.text()).toBe(true)
		expect((await visibility.json()).archiveHotspot).toBe(subject)

		await page.goto(`${APP_BASE}/publications/${encodeURIComponent(catalogSlug)}/${publicationId}`)
		await expect(page.getByTestId('publication-visibility-archive-hotspot'))
			.toHaveText(`Kept permanently: archive hotspot ${subject}`)
	})

	test('unmarking is logged too', async ({ request }) => {
		const res = await request.patch(`${THEMES}/${themeId}`, {
			data: { archiveHotspot: false, archiveHotspotReason: 'Lijst herzien' },
		})
		expect(res.ok(), await res.text()).toBe(true)
		expect((await (await request.get(`${THEMES}/${themeId}`)).json()).archiveHotspot).toBe(false)

		const trail = await request.get(`/index.php/apps/openregister/api/audit-trails?object_uuid=${themeId}`)
		const entries = ((await trail.json())?.results ?? []) as Array<{ action?: string }>
		expect(entries.filter((entry) => entry.action === 'theme.archiveHotspot.unmarked')).toHaveLength(1)
	})
})
