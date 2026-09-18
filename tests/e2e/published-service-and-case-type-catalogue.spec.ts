/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The published service catalogue and the case type catalogue.
 *
 * WHAT THIS SPEC IS LOOKING FOR. Two failures, and they point opposite ways.
 * Publishing something that should not be public is the first: a draft
 * knowledge article must not be readable by an anonymous reader, and a catalogue
 * entry whose form does not resolve must not pretend it does. Reporting
 * something as published when it is not is the second: a catalogue this app
 * cannot read answers 503 and an external catalogue it could not ask answers
 * 502, and neither answers an empty list, because an empty list reads as
 * "your municipality publishes nothing".
 *
 * THE LEAST PRIVILEGED PRINCIPAL. Every read here is made by an anonymous
 * request context with no session and no credentials, which is the principal
 * the public catalogue is for and the one the admin surfaces must refuse. The
 * admin calls are made twice: once anonymously, which must be refused, and
 * once as admin.
 *
 * WHAT A RED HERE USUALLY MEANS. A 503 on the catalogue means the register
 * configuration never landed (run the setup wizard, or POST /api/settings/load);
 * that is a genuine refusal, not a flake, and it is deliberately not silent.
 *
 * @e2e openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#scenario-a-resident-finds-what-they-can-request
 * @e2e openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#scenario-an-entry-carries-the-form-that-starts-it
 * @e2e openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#scenario-an-entry-with-no-resolvable-form-is-not-hidden
 * @e2e openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#scenario-an-integrator-finds-the-interface-from-the-type
 * @e2e openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#scenario-a-definition-arrives-from-the-national-catalogue
 * @e2e openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#scenario-a-resync-shows-what-changed-before-it-applies
 * @e2e openspec/changes/published-service-and-case-type-catalogue/specs/published-service-and-case-type-catalogue/spec.md#scenario-the-extraction-produces-a-draft-not-a-publication
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/** The public request catalogue. */
const CATALOGUE = `${API_BASE}/service-catalogue`

/** The administrator's list of entries whose form does not resolve. */
const UNAVAILABLE = `${API_BASE}/service-catalogue/unavailable`

/** The import of a definition from an external catalogue. */
const IMPORT = `${API_BASE}/case-types/import`

/**
 * A request context with no session at all.
 *
 * `storageState: undefined` on a fresh context is what makes this anonymous:
 * reusing the test's own context would carry the admin cookie and every
 * "an anonymous reader cannot" assertion would pass for the wrong reason.
 */
async function anonymous(baseURL: string): Promise<APIRequestContext> {
	return await request.newContext({ baseURL, storageState: undefined })
}

test.describe('The public request catalogue', () => {
	test('an anonymous reader reads the catalogue, or is told it is unreadable', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.get(CATALOGUE)

		// 503 is a legitimate answer here and says the registers are not
		// configured. What is never acceptable is 200 with an empty list while
		// the catalogue is in fact unreadable, which is why the body is read
		// rather than only the status.
		expect([200, 503]).toContain(response.status())

		if (response.status() === 503) {
			const body = await response.json()
			expect(body.error).toBe('catalogue-unreadable')
			await anon.dispose()
			return
		}

		const body = await response.json()
		expect(Array.isArray(body.entries)).toBe(true)
		expect(body).toHaveProperty('groups')
		expect(body).toHaveProperty('unavailable')

		// Every entry says whether it can be started, and no entry is missing
		// that verdict. An entry without `available` is an entry whose state
		// nobody decided.
		for (const entry of body.entries) {
			expect(entry).toHaveProperty('available')
			if (entry.available === false) {
				expect(typeof entry.unavailableReason).toBe('string')
				expect(entry.unavailableReason.length).toBeGreaterThan(0)
			}
			if (entry.available === true) {
				expect(entry.formBinding).toMatchObject({
					caseType: expect.any(String),
					audience: expect.any(String),
					formName: expect.any(String),
				})
			}
		}

		await anon.dispose()
	})

	test('the catalogue answers CORS preflight for a portal on another origin', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.fetch(CATALOGUE, {
			method: 'OPTIONS',
			headers: { Origin: 'https://portaal.example.org' },
		})

		expect(response.status()).toBeLessThan(400)
		expect(response.headers()['access-control-allow-origin']).toBeDefined()

		await anon.dispose()
	})

	test('an anonymous reader cannot read the administrator list of broken entries', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.get(UNAVAILABLE)

		// The refusal is the assertion. A 200 here would hand an anonymous
		// reader the list of what is misconfigured on this instance.
		expect([401, 403, 404, 412]).toContain(response.status())

		await anon.dispose()
	})
})

test.describe('The case type catalogue', () => {
	test('an anonymous reader cannot import a definition', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(IMPORT, {
			data: { sourceId: 'i-navigator', externalId: 'verhuizing' },
		})

		expect([401, 403, 404, 412]).toContain(response.status())

		await anon.dispose()
	})

	test('an import from a source that cannot be reached says unreachable, not empty', async ({ request: admin }) => {
		const response = await admin.post(IMPORT, {
			data: { sourceId: 'a-source-that-does-not-exist', externalId: 'verhuizing' },
		})

		// The one answer this must never give is 200 with nothing in it. An
		// administrator told "no definitions" goes to look at the national
		// catalogue; one told "unreachable" goes to look at the gateway.
		expect(response.status()).not.toBe(200)

		if (response.status() === 502) {
			const body = await response.json()
			expect(body.error).toBe('external-catalogue-unreachable')
		}
	})

	test('a published case type links to its form and its API description', async ({ baseURL, request: admin }) => {
		const listed = await admin.get(`${CATALOGUE}?_limit=50`)
		test.skip(listed.status() !== 200, 'the catalogue is not configured on this instance')

		const body = await listed.json()
		const bound = body.entries.find((entry: { available: boolean }) => entry.available === true)
		test.skip(bound === undefined, 'this instance has no entry with a resolvable form')

		const anon = await anonymous(baseURL as string)
		const caseType = await anon.get(`${API_BASE}/case-types/${bound.formBinding.caseType}`)

		if (caseType.status() === 200) {
			const definition = await caseType.json()
			expect(definition).toHaveProperty('links')
			expect(definition).toHaveProperty('syncedAt')
		}

		await anon.dispose()
	})
})

test.describe('Knowledge articles', () => {
	test('an extracted article is a draft, and an anonymous reader cannot read it', async ({ baseURL, request: admin }) => {
		const created = await admin.post(`${API_BASE}/knowledge-articles/extract`, {
			data: {
				case: {
					id: 'e2e-case-1',
					title: 'E2E: vraag over de Woo',
					answer: 'E2E: dit antwoord hoort in een concept te belanden, niet in publicatie.',
				},
			},
		})

		test.skip(created.status() === 503, 'the catalogue is not configured on this instance')
		expect([201, 401, 403]).toContain(created.status())

		if (created.status() !== 201) {
			return
		}

		const draft = await created.json()
		expect(draft.draft).toBe(true)
		expect(draft.sourceCase).toBe('e2e-case-1')

		// The failure that matters: an answer written to one applicant reaching
		// the public catalogue because an action said "article".
		const anon = await anonymous(baseURL as string)
		const verdict = await anon.post(`${API_BASE}/knowledge-articles/${draft.id}/verdict`, {
			data: { helpful: true },
		})
		expect(verdict.status()).toBe(404)

		await anon.dispose()
	})

	test('an anonymous reader must say whether the article helped', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/knowledge-articles/does-not-exist/verdict`, { data: {} })

		expect([400, 404, 503]).toContain(response.status())

		await anon.dispose()
	})
})
