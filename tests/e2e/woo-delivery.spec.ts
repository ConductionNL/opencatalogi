/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * National delivery repair: channel sources and the announce endpoint.
 *
 * WHAT THIS SPEC IS LOOKING FOR. A delivery to a national channel that could
 * not be made must never read as made. With no integriq source set for a
 * channel, the announce endpoint answers 502, names the channel and the
 * `channel_sources` setting, and does not mark the notice as sent.
 *
 * @e2e openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#scenario-integriq-is-not-installed
 * @e2e openspec/changes/woo-national-delivery-repair/specs/woo-national-delivery-repair/spec.md#scenario-an-admin-announces-a-decision
 */
import { expect, test } from './authenticated-request.ts'

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/** A decision with everything the publication gateway reads. */
const DECISION = {
	id: 'e2e-besluit-1',
	title: 'E2E Besluit Dorpsstraat',
	url: 'https://gemeente.example.nl/besluiten/e2e-besluit-1',
	publicationDate: '2026-10-01',
	publicationType: 'gemeenteblad',
	effectiveDate: '2026-10-01',
}

test.describe('National channel sources', () => {
	test('the settings keep only known channels with a source slug', async ({
		request: admin,
	}) => {
		const saved = await admin.put(`${API_BASE}/settings`, {
			data: {
				channel_sources: {
					plooi: ' plooi-api ',
					'national-woo-index': '',
					'somewhere-else': 'x',
				},
			},
		})
		expect(saved.ok()).toBe(true)

		const read = await admin.get(`${API_BASE}/settings`)
		const body = await read.json()
		expect(JSON.parse(body.configuration.channel_sources)).toEqual({
			plooi: 'plooi-api',
		})
	})

	test('a notice for a channel with no source is not reported as sent', async ({
		request: admin,
	}) => {
		await admin.put(`${API_BASE}/settings`, { data: { channel_sources: {} } })

		const response = await admin.post(`${API_BASE}/publications/announce`, {
			data: { decision: DECISION },
		})

		expect(response.status()).toBe(502)
		const body = await response.json()
		expect(body.complete).toBe(false)
		const national = body.unreachable.find(
			(entry: { channel: string }) =>
				entry.channel === 'national-publication-platform',
		)
		expect(national).toBeTruthy()
		expect(national.reason).toContain('channel_sources')
		expect(
			body.delivered.map((entry: { channel: string }) => entry.channel),
		).not.toContain('national-publication-platform')
	})
})
