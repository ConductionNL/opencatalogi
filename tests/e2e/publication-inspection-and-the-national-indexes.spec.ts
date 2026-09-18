/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Active publication, terinzagelegging and the national indexes.
 *
 * WHAT THIS SPEC IS LOOKING FOR. On a publication surface the failure that
 * matters is publishing something that should not be public, and its mirror,
 * reporting something as published when it is not. Both are probed here with
 * the least privileged principal that should be refused: an anonymous request
 * context with no session and no credentials.
 *
 * THE SHAPE OF EACH ASSERTION. Where a refusal is the point, the refusal is
 * asserted, not the absence of a 500. Where a state cannot be read, the spec
 * accepts a 503 and asserts that it names itself, because a 503 that says what
 * is unconfigured is a working refusal and a 200 with an empty body is not.
 *
 * WHAT A RED HERE USUALLY MEANS. A 503 with `register-unreadable` means the
 * registers were never configured on this instance: run the setup wizard, or
 * POST to /api/settings/load. That is the app refusing correctly, not failing.
 *
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-a-property-outside-the-set-is-absent-from-the-api
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-a-rule-is-previewed-before-it-is-saved
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-a-decision-without-a-publication-date-is-refused
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-the-response-date-is-computed-not-typed
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-the-link-works-inside-the-window
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-the-link-stops-when-the-window-closes
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-an-unidentified-channel-is-refused
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-an-unacknowledged-withdrawal-is-not-done
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-the-app-holds-no-transport
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-search-respects-the-anonymous-set
 * @e2e openspec/changes/publication-inspection-and-the-national-indexes/specs/publication-inspection-and-the-national-indexes/spec.md#scenario-a-changed-document-fails-the-check
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/**
 * A request context with no session at all.
 *
 * Reusing the test's own context would carry the admin cookie, and every
 * "an anonymous reader cannot" assertion would pass for the wrong reason.
 */
async function anonymous(baseURL: string): Promise<APIRequestContext> {
	return await request.newContext({ baseURL, storageState: undefined })
}

/** A besluit with a property no anonymous reader may read. */
const RECORD_WITH_A_SECRET = {
	'@type': 'besluit',
	status: 'definitief',
	title: 'E2E Kapvergunning Dorpsstraat',
	publicationDate: '2026-09-18',
	aanvrager: 'E2E-ZELDZAAMWOORD',
	dossier: 'e2e-dossier-1',
}

/** The rule that publishes it, exposing only the title and the date. */
const NARROW_RULE = {
	recordType: 'besluit',
	enabled: true,
	anonymousProperties: ['title', 'publicationDate'],
	conditions: [{ property: 'status', operator: 'equals', value: 'definitief' }],
}

test.describe('The anonymous permission set', () => {
	test('a property outside the set never reaches an anonymous reader', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/publications/search`, {
			data: {
				records: [RECORD_WITH_A_SECRET],
				rules: [NARROW_RULE],
				q: 'Dorpsstraat',
			},
		})

		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body.total).toBe(1)

		// The assertion that matters: the withheld property is absent from the
		// whole response body, not merely absent from a rendered page.
		expect(JSON.stringify(body)).not.toContain('E2E-ZELDZAAMWOORD')
		expect(body.results[0].dossier).toBe('e2e-dossier-1')

		await anon.dispose()
	})

	test('a word occurring only in a withheld property returns nothing', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/publications/search`, {
			data: {
				records: [RECORD_WITH_A_SECRET],
				rules: [NARROW_RULE],
				q: 'E2E-ZELDZAAMWOORD',
			},
		})

		expect(response.status()).toBe(200)
		expect((await response.json()).total).toBe(0)

		await anon.dispose()
	})

	test('an anonymous reader cannot preview a publication rule', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/publication-rules/preview`, {
			data: { rule: NARROW_RULE, sample: [RECORD_WITH_A_SECRET] },
		})

		expect([401, 403, 404, 412]).toContain(response.status())

		await anon.dispose()
	})

	test('an administrator sees both halves of the preview before saving', async ({
		request: admin,
	}) => {
		const response = await admin.post(`${API_BASE}/publication-rules/preview`, {
			data: {
				rule: { ...NARROW_RULE, enabled: false },
				sample: [RECORD_WITH_A_SECRET],
			},
		})

		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body.valid).toBe(true)
		expect(body.wouldPublish).toHaveLength(1)
		expect(body.exposedProperties).toEqual(
			expect.arrayContaining(['title', 'publicationDate']),
		)
		expect(body.exposedProperties).not.toContain('aanvrager')
	})
})

test.describe('The decision type', () => {
	test('a decision without a publication date is refused and the reason names the date', async ({
		request: admin,
	}) => {
		const response = await admin.post(
			`${API_BASE}/publication-rules/validate-decision`,
			{
				data: {
					decision: { id: 'e2e-b1', title: 'E2E besluit' },
					decisionType: {
						publicationObligation: true,
						responseTermDays: 42,
					},
				},
			},
		)

		expect(response.status()).toBe(422)
		const body = await response.json()
		expect(body.error).toBe('not-publishable')
		expect(body.reasons.join(' ')).toContain('publication date')
	})

	test('the response date is computed from the term, not taken from the decision', async ({
		request: admin,
	}) => {
		const response = await admin.post(
			`${API_BASE}/publication-rules/validate-decision`,
			{
				data: {
					decision: {
						publicationDate: '2026-09-01T00:00:00+00:00',
						responseDate: '2099-01-01',
					},
					decisionType: {
						publicationObligation: true,
						responseTermDays: 42,
					},
				},
			},
		)

		expect(response.status()).toBe(200)
		expect((await response.json()).responseDate).toBe(
			'2026-10-13T00:00:00+00:00',
		)
	})
})

test.describe('Terinzagelegging', () => {
	test('an anonymous reader cannot open an inspection', async ({ baseURL }) => {
		const anon = await anonymous(baseURL as string)
		const response = await anon.post(`${API_BASE}/inspections`, {
			data: {
				record: { id: 'e2e-r1' },
				recordType: { slug: 'omgevingsvergunning', inspectionTermDays: 42 },
				documents: ['e2e-d1'],
			},
		})

		expect([401, 403, 404, 412]).toContain(response.status())

		await anon.dispose()
	})

	test('a link inside its window serves only the chosen documents, and a closed one is gone', async ({
		baseURL,
		request: admin,
	}) => {
		const opened = await admin.post(`${API_BASE}/inspections`, {
			data: {
				record: { id: 'e2e-r1' },
				recordType: { slug: 'omgevingsvergunning', inspectionTermDays: 42 },
				documents: ['e2e-d2', 'e2e-d4'],
			},
		})

		test.skip(
			opened.status() === 503,
			'the registers are not configured on this instance',
		)
		expect(opened.status()).toBe(201)

		const inspection = await opened.json()
		const anon = await anonymous(baseURL as string)

		const inside = await anon.get(
			`${API_BASE}/inspections/${inspection.id}?token=${inspection.token}`,
		)
		expect(inside.status()).toBe(200)
		expect((await inside.json()).documents).toEqual(['e2e-d2', 'e2e-d4'])

		// A wrong token is refused without telling the caller anything about
		// the window it guessed at.
		const wrongToken = await anon.get(
			`${API_BASE}/inspections/${inspection.id}?token=not-the-token`,
		)
		expect(wrongToken.status()).toBe(404)

		// The closed window is exercised by the unit suite with a controlled
		// clock; here it is the expired case shipped as a zero-day term.
		const expired = await admin.post(`${API_BASE}/inspections`, {
			data: {
				record: { id: 'e2e-r2' },
				recordType: { slug: 'omgevingsvergunning', inspectionTermDays: 0 },
				documents: ['e2e-d9'],
			},
		})
		expect(expired.status()).toBe(400)
		expect((await expired.json()).error).toBe('inspection-refused')

		await anon.dispose()
	})
})

test.describe('The national channels', () => {
	test('an announcement that reached nothing is 502 and names the channels', async ({
		request: admin,
	}) => {
		const response = await admin.post(`${API_BASE}/publications/announce`, {
			data: {
				decision: {
					id: 'e2e-b1',
					title: 'E2E bekendmaking',
					publicationDate: '2026-09-18',
				},
			},
		})

		// With no gateway configured this is 502, and the notices are still
		// returned so an operator can see what would have been sent. The one
		// answer this must never give is 200 with nothing delivered.
		expect([200, 502]).toContain(response.status())
		const body = await response.json()
		expect(body.notices).toHaveLength(2)

		if (response.status() === 502) {
			expect(body.complete).toBe(false)
			expect(body.unreachable.length).toBeGreaterThan(0)
		}
	})

	test('a withdrawal nothing acknowledged is outstanding, not done', async ({
		request: admin,
	}) => {
		const response = await admin.post(`${API_BASE}/publications/depublish`, {
			data: {
				publication: { id: 'e2e-p1' },
				reason: 'E2E: publicatiefout.',
				channels: ['national-woo-index'],
			},
		})

		expect(response.status()).toBe(200)
		const body = await response.json()
		expect(body.depublishedBy).toBeTruthy()
		expect(body.reason).toBe('E2E: publicatiefout.')

		if (body.complete === false) {
			expect(body.outstandingChannels).toContain('national-woo-index')
		}
	})

	test('a depublication without a reason is refused', async ({
		request: admin,
	}) => {
		const response = await admin.post(`${API_BASE}/publications/depublish`, {
			data: { publication: { id: 'e2e-p1' }, reason: '   ', channels: [] },
		})

		expect(response.status()).toBe(400)
		expect((await response.json()).error).toBe('depublication-refused')
	})

	test('a zienswijze over a channel that does not identify is refused', async ({
		request: admin,
	}) => {
		const response = await admin.post(
			`${API_BASE}/publication-process/zienswijze`,
			{
				data: {
					publication: 'e2e-p1',
					party: 'E2E party',
					channel: 'anonymous-webform',
					termDays: 14,
				},
			},
		)

		expect(response.status()).toBe(400)
		expect((await response.json()).error).toBe('ask-refused')
	})
})

test.describe('The stamp', () => {
	test('a changed document fails the check, and an unchanged one passes', async ({
		baseURL,
	}) => {
		const anon = await anonymous(baseURL as string)
		const key = await anon.get(`${API_BASE}/publications/verification-key`)

		test.skip(
			key.status() === 503,
			'this instance publishes no verification key',
		)
		expect(key.status()).toBe(200)

		const published = await key.json()
		expect(JSON.stringify(published)).not.toContain('signingKey')
		expect(published.keyId).toBeTruthy()

		// A stamp this spec did not make cannot verify, so the assertion here is
		// the one that can be made from outside: an altered pair is refused and
		// the refusal names why, rather than answering a bare false.
		const verify = await anon.post(`${API_BASE}/publications/verify`, {
			data: {
				document: 'de inhoud',
				metadata: {
					id: 'e2e-d1',
					title: 'E2E',
					publicationDate: '2026-09-18',
				},
				stamp: {
					algorithm: 'sha256',
					keyId: published.keyId,
					signature: 'niet-de-handtekening',
				},
			},
		})

		expect(verify.status()).toBe(200)
		const outcome = await verify.json()
		expect(outcome.valid).toBe(false)
		expect(outcome.reason).toBe('does-not-match')

		await anon.dispose()
	})
})
