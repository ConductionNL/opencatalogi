/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publications-name-their-responsible-organisation REQ-PRO-001: a publication
 * names the organisation responsible for it apart from its publisher, so a
 * shared service centre can publish for a municipality.
 *
 * WHAT THIS SPEC IS LOOKING FOR:
 * - The publication form offers Responsible organisation beside the
 *   publisher, with the same organisation picker.
 * - Saved, the publication holds `responsibleOrganization` (the uuid of the
 *   shared organisation) next to `organization`, read back through
 *   OpenRegister's object API.
 * - The publication page shows the publisher and the responsible organisation
 *   separately, each by name.
 *
 * THE FIXTURE. Two organisations made through OpenRegister's organisation API
 * (a service centre that publishes and the municipality it publishes for), one
 * catalogue and one draft publication of the service centre. All are removed
 * in afterAll. Texts are matched in English and Dutch.
 *
 * Written to run in the nightly against a live instance; not run in the
 * cloud sandbox.
 *
 * @e2e openspec/changes/publications-name-their-responsible-organisation/specs/woo-compliance/spec.md#scenario-an-editor-sets-the-responsible-organisation REQ-PRO-001
 * @spec openspec/changes/publications-name-their-responsible-organisation/tasks.md#task-1.3
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog } from './publication-catalog.ts'
import { dismissOverlays } from './spec-coverage/_nav.ts'

const APP = '/index.php/apps/opencatalogi'
const OR_API = '/index.php/apps/openregister/api'
const PUBLICATIONS = `${OR_API}/objects/publication/publication`
const RUN = `pro-${Date.now().toString(36)}`
const PUBLISHER = `Servicecentrum Drechtsteden ${RUN}`
const RESPONSIBLE = `Gemeente Dordrecht ${RUN}`
const RESPONSIBLE_LABEL = /Responsible organisation|Verantwoordelijke organisatie/

test.describe('Responsible organisation', () => {
	test.describe.configure({ mode: 'serial' })

	let publisherId = ''
	let responsibleId = ''
	let publicationId = ''
	let catalogSlug = ''

	/**
	 * Make one organisation through OpenRegister's organisation API.
	 *
	 * @param request The admin request context.
	 * @param name The organisation's name.
	 * @return The organisation's uuid.
	 */
	async function organisation(
		request: APIRequestContext,
		name: string,
	): Promise<string> {
		const created = await request.post(`${OR_API}/organisations`, {
			data: { name, description: `E2E ${RUN}` },
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		const record = body.organisation ?? body
		const uuid = String(record.uuid ?? record.id ?? '')
		expect(uuid, `${name} has a uuid`).not.toBe('')
		return uuid
	}

	test.beforeAll(async ({ request }) => {
		publisherId = await organisation(request, PUBLISHER)
		responsibleId = await organisation(request, RESPONSIBLE)
		catalogSlug = (await publicationCatalog(request, RUN)).slug

		const created = await request.post(PUBLICATIONS, {
			data: {
				title: `E2E responsible organisation ${RUN}`,
				summary: 'A draft published by a service centre for a municipality.',
				organization: publisherId,
			},
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		publicationId = String(body?.id ?? body?.['@self']?.id ?? body?.uuid ?? '')
		expect(publicationId, 'the draft has an id').not.toBe('')
	})

	test.afterAll(async ({ request }) => {
		if (publicationId !== '') {
			await request
				.delete(`${PUBLICATIONS}/${publicationId}`)
				.catch(() => undefined)
		}
		for (const uuid of [responsibleId, publisherId]) {
			if (uuid !== '') {
				await request
					.delete(`${OR_API}/organisations/${uuid}`)
					.catch(() => undefined)
			}
		}
	})

	test('the form offers Responsible organisation beside the publisher', async ({
		page,
	}) => {
		await page.goto(
			`${APP}/publications/${encodeURIComponent(catalogSlug)}/${publicationId}`,
		)
		await dismissOverlays(page)

		const edit = page.locator('[data-testid="cn-detail-page-edit"]').first()
		await expect(edit).toBeVisible({ timeout: 20000 })
		await edit.click()
		const form = page.getByRole('dialog').first()
		await expect(form).toBeVisible({ timeout: 10000 })
		await expect(form.getByText(RESPONSIBLE_LABEL).first()).toBeVisible()
	})

	test('an editor sets the responsible organisation', async ({
		request,
		page,
	}) => {
		const saved = await request.patch(`${PUBLICATIONS}/${publicationId}`, {
			data: { responsibleOrganization: responsibleId },
		})
		expect(saved.ok(), await saved.text()).toBe(true)

		const stored = await (
			await request.get(`${PUBLICATIONS}/${publicationId}`)
		).json()
		expect(stored.organization, 'the publisher stays').toBe(publisherId)
		expect(
			stored.responsibleOrganization,
			'the responsible organisation is stored apart',
		).toBe(responsibleId)

		await page.goto(
			`${APP}/publications/${encodeURIComponent(catalogSlug)}/${publicationId}`,
		)
		await dismissOverlays(page)
		await expect(page.getByText(PUBLISHER).first()).toBeVisible({
			timeout: 20000,
		})
		await expect(page.getByText(RESPONSIBLE).first()).toBeVisible()
		await expect(page.getByText(RESPONSIBLE_LABEL).first()).toBeVisible()
	})
})
