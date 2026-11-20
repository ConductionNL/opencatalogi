/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for woo-metadata-suggestions (tasks 4.2 and 6.2): an editor
 * sees a pending suggestion on the publication page, accepts it and sees the
 * field filled; a rejected suggestion leaves the field as it was; and a field
 * a rule filled when the publication was created is labelled as filled
 * automatically.
 *
 * Fixtures: publications in the publication register and schema of a catalog
 * (resolved by slug, as the other publication specs do), and suggestions
 * seeded as `metadataSuggestion` objects with source `rules`, which is what
 * the rules floor writes in the background. Removed afterwards.
 *
 * @spec openspec/changes/woo-metadata-suggestions/specs/woo-metadata-suggestions/spec.md
 */
import type { APIRequestContext } from '@playwright/test'
import type { PublicationCatalog, PublicationScope } from './publication-catalog.ts'

import { expect, test } from './authenticated-request.ts'
import { publicationCatalog, publicationScope } from './publication-catalog.ts'
import { APP, dismissOverlays } from './spec-coverage/_nav.ts'

const OR_API = '/index.php/apps/openregister/api'
const API = '/index.php/apps/opencatalogi/api'
const RUN_ID = `wms-${Date.now()}`

let scope: PublicationScope
let catalog: PublicationCatalog
const created: string[] = []

/**
 * The id of an OpenRegister row.
 *
 * @param body The response body.
 */
function idOf(body: Record<string, any>): string {
	return String(body?.id ?? body?.uuid ?? body?.['@self']?.id ?? '')
}

/**
 * Create an object as the admin and remember it for cleanup.
 *
 * @param request The signed-in request context.
 * @param register The register id or slug.
 * @param schema The schema id or slug.
 * @param data The fields.
 */
async function create(
	request: APIRequestContext,
	register: string | number,
	schema: string | number,
	data: Record<string, unknown>,
): Promise<Record<string, any>> {
	const url = `${OR_API}/objects/${register}/${schema}`
	const res = await request.post(url, { data })
	expect(res.status(), `POST ${url}: ${await res.text()}`).toBeLessThan(300)
	const body = await res.json()
	created.push(`${url}/${idOf(body)}`)
	return body
}

/**
 * A publication of this run with one pending suggestion for its summary.
 *
 * @param request The signed-in request context.
 * @param name A name for the run label.
 * @param proposed The proposed summary.
 */
async function publicationWithSuggestion(
	request: APIRequestContext,
	name: string,
	proposed: string,
): Promise<{ publication: string; suggestion: string }> {
	const publication = idOf(
		await create(request, scope.register, scope.schema, {
			title: `${RUN_ID} ${name}`,
			summary: 'Samenvatting volgt',
		}),
	)
	const suggestion = idOf(
		await create(request, scope.register, 'metadataSuggestion', {
			publication,
			field: 'summary',
			value: proposed,
			valueLabel: proposed,
			source: 'rules',
			reason: 'The first paragraph of the decision says what it is about.',
			status: 'pending',
		}),
	)
	return { publication, suggestion }
}

test.describe('Woo metadata suggestions on the publication page', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ request }) => {
		scope = await publicationScope(request)
		catalog = await publicationCatalog(request, RUN_ID)
	})

	test.afterAll(async ({ request }) => {
		for (const url of created.reverse()) {
			await request.delete(url).catch(() => undefined)
		}
	})

	// @e2e woo-metadata-suggestions REQ-WMS-003
	test('an editor accepts a suggestion and the field is filled', async ({
		page,
		request,
	}) => {
		const proposed = `Besluit op het Woo-verzoek over de verbouwing van het stadhuis (${RUN_ID})`
		const { publication, suggestion } = await publicationWithSuggestion(
			request,
			'accept',
			proposed,
		)

		const listed = await request.get(
			`${API}/publications/${publication}/metadata-suggestions`,
		)
		expect(listed.status(), await listed.text()).toBe(200)
		expect(JSON.stringify(await listed.json())).toContain(suggestion)

		await page.goto(
			`${APP}/publications/${encodeURIComponent(catalog.slug)}/${publication}`,
		)
		await dismissOverlays(page)
		const widget = page.getByTestId('metadata-suggestions')
		await expect(widget).toBeVisible({ timeout: 30_000 })
		const row = page.getByTestId(`metadata-suggestion-${suggestion}`)
		await expect(row).toContainText(proposed)
		await page.getByTestId(`metadata-suggestion-accept-${suggestion}`).click()
		await expect(row).toHaveCount(0, { timeout: 20_000 })

		const stored = await request.get(
			`${OR_API}/objects/${scope.register}/${scope.schema}/${publication}`,
		)
		expect(
			(await stored.json()).summary,
			'the accepted value is on the publication',
		).toBe(proposed)
		await expect(page.getByText(proposed).first()).toBeVisible({
			timeout: 20_000,
		})

		const decided = await request.get(
			`${OR_API}/objects/${scope.register}/metadataSuggestion/${suggestion}`,
		)
		const record = await decided.json()
		expect(record.status).toBe('accepted')
		expect(record.decidedBy).toBe(process.env.NC_ADMIN_USER || 'admin')
	})

	// @e2e woo-metadata-suggestions REQ-WMS-003
	test('a rejected suggestion leaves the field as it was', async ({
		page,
		request,
	}) => {
		const proposed = `Een samenvatting die niet klopt (${RUN_ID})`
		const { publication, suggestion } = await publicationWithSuggestion(
			request,
			'reject',
			proposed,
		)

		await page.goto(
			`${APP}/publications/${encodeURIComponent(catalog.slug)}/${publication}`,
		)
		await dismissOverlays(page)
		await page
			.getByTestId(`metadata-suggestion-reject-${suggestion}`)
			.click({ timeout: 30_000 })
		await expect(
			page.getByTestId(`metadata-suggestion-${suggestion}`),
		).toHaveCount(0, {
			timeout: 20_000,
		})

		const stored = await request.get(
			`${OR_API}/objects/${scope.register}/${scope.schema}/${publication}`,
		)
		expect((await stored.json()).summary).toBe('Samenvatting volgt')

		// A decided suggestion cannot be decided again.
		const again = await request.post(
			`${API}/metadata-suggestions/${suggestion}/accept`,
		)
		expect(again.status()).toBe(409)
	})

	// @e2e woo-metadata-suggestions REQ-WMS-004
	test('an officer of one organisation does not pick it', async ({
		page,
		request,
	}) => {
		const publication = await create(request, scope.register, scope.schema, {
			title: `${RUN_ID} one organisation`,
			summary: 'Filled by rule fixture',
		})
		const filled: Array<{ field: string }> = publication.filledByRule ?? []
		test.skip(
			!filled.some((f) => f.field === 'organization'),
			'the signed-in officer has more than one organisation here, so no organisation is filled by rule',
		)

		await page.goto(
			`${APP}/publications/${encodeURIComponent(catalog.slug)}/${idOf(publication)}`,
		)
		await dismissOverlays(page)
		const note = page.getByTestId('metadata-filled-organization')
		await expect(note).toBeVisible({ timeout: 30_000 })
		await expect(note).toContainText(
			'Filled automatically: the only value you can choose',
		)
		expect(
			publication.organization,
			'the one lawful organisation is set',
		).toBeTruthy()
	})
})
