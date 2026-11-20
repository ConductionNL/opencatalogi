import { request as playwrightRequest } from '@playwright/test'
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A failed PLOOI delivery reaches the Woo team.
 *
 * WHAT THIS SPEC IS LOOKING FOR. With `woo_incident_group` set to a group and a
 * PLOOI channel that fails, delivering a public publication raises one
 * `wooPipelineIncident`: the Woo settings list it as open, and a member of the
 * group, signed in on their own with an empty storage state, has a Nextcloud
 * notification naming the publication and the reason. The e-mail goes out on the
 * same rule; its log entry is checked on the dev instance (task 4.4), because
 * this rig has no mail catcher.
 *
 * The failing PLOOI fake is a channel source slug no source answers to: the
 * delivery then fails with IndexUnreachableException exactly as a refusing
 * platform does, without anything leaving the instance.
 *
 * @e2e openspec/changes/woo-national-output-assurance/specs/woo-compliance/spec.md#scenario-a-failed-plooi-delivery-reaches-the-woo-team REQ-WNO-004
 */
import { execFile } from 'node:child_process'
import { promisify } from 'node:util'
import { expect, test } from './authenticated-request.ts'
import { BASE_URL } from './base-url.ts'
import { occPrefix, refuseOnSharedInstance } from './shared-instance.ts'
import { Fixtures, newRunId } from './workflows/_fixtures.ts'

const run = promisify(execFile)

/** The app's own API base path. */
const API_BASE = '/index.php/apps/opencatalogi/api'

/** OCS asks for this header on every call. */
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const RUN = newRunId()
const GROUP = `${RUN}-woo-team`
const MEMBER = `${RUN}-woo-member`
const MEMBER_PASSWORD = `E2e-${RUN}-Woo-pw-2026!`

test.describe('A pipeline failure reaches a named group', () => {
	const fx = new Fixtures()

	test.beforeAll(async ({ request: admin }) => {
		refuseOnSharedInstance(
			BASE_URL,
			'This spec',
			'it creates a user and a group and changes the PLOOI channel source',
		)
		await fx.init()

		const group = await admin.post('/ocs/v2.php/cloud/groups', {
			headers: OCS,
			form: { groupid: GROUP },
		})
		expect(group.ok(), 'the admin creates the incident group').toBe(true)
		const user = await admin.post('/ocs/v2.php/cloud/users', {
			headers: OCS,
			form: { userid: MEMBER, password: MEMBER_PASSWORD, 'groups[]': GROUP },
		})
		expect(user.ok(), 'the admin creates a member of it').toBe(true)
	})

	test.afterAll(async ({ request: admin }) => {
		await admin.put(`${API_BASE}/woo/incidents/group`, { data: { group: '' } })
		await admin.put(`${API_BASE}/settings`, { data: { channel_sources: {} } })
		await admin.delete(`/ocs/v2.php/cloud/users/${MEMBER}`, { headers: OCS })
		await admin.delete(`/ocs/v2.php/cloud/groups/${GROUP}`, { headers: OCS })
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('a group that does not exist is refused', async ({ request: admin }) => {
		const refused = await admin.put(`${API_BASE}/woo/incidents/group`, {
			data: { group: `${RUN}-nobody` },
		})

		expect(refused.status()).toBe(400)
	})

	test('a failed PLOOI delivery reaches the Woo team', async ({
		request: admin,
	}) => {
		const saved = await admin.put(`${API_BASE}/woo/incidents/group`, {
			data: { group: GROUP },
		})
		expect(saved.status()).toBe(200)
		expect((await saved.json()).group).toBe(GROUP)

		const channel = await admin.put(`${API_BASE}/settings`, {
			data: { channel_sources: { plooi: `${RUN}-failing-plooi` } },
		})
		expect(channel.ok()).toBe(true)

		await fx.createCatalog('plooi', { plooiDelivery: true })
		// A draft first: the listener delivers a publication when it turns public on an update.
		const publication = await fx.createPublication('plooi')
		const { '@self': _self, ...record } = publication.raw as Record<
			string,
			unknown
		>
		const published = await admin.put(
			`/index.php/apps/openregister/api/objects/${publication.register}/${publication.schema}/${publication.id}`,
			{ data: { ...record, publicationDate: '2026-01-05T09:00:00+00:00' } },
		)
		expect(published.ok(), 'the publication turns public').toBe(true)

		// The listener queues the delivery; the worker runs it now instead of at the next cron.
		const [occ, ...args] = occPrefix()
		await run(
			occ,
			[
				...args,
				'background-job:worker',
				'--once',
				'OCA\\OpenCatalogi\\BackgroundJob\\PlooiDelivery',
			],
			{ timeout: 120_000 },
		)

		let incident: Record<string, string> | undefined
		await expect
			.poll(
				async () => {
					const listed = await admin.get(`${API_BASE}/woo/incidents`)
					const body = await listed.json()
					incident = (body.incidents ?? []).find(
						(open: Record<string, string>) =>
							open.kind === 'plooi-delivery'
							&& open.subject === publication.id,
					)
					return incident !== undefined
				},
				{
					timeout: 60_000,
					message: 'the Woo settings list the open incident',
				},
			)
			.toBe(true)
		expect(incident?.message).toContain(publication.title)
		expect(incident?.message).toContain(`${RUN}-failing-plooi`)

		// The member reads their own notifications, signed in as themselves.
		const member = await playwrightRequest.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: MEMBER, password: MEMBER_PASSWORD },
			extraHTTPHeaders: OCS,
		})
		try {
			await expect
				.poll(
					async () => {
						const notifications = await member.get(
							'/ocs/v2.php/apps/notifications/api/v2/notifications',
						)
						if (!notifications.ok()) {
							return ''
						}

						const body = await notifications.json()
						return JSON.stringify(body?.ocs?.data ?? [])
					},
					{
						timeout: 60_000,
						message:
							'a member of the group has a notification naming the publication',
					},
				)
				.toContain(publication.title)
		} finally {
			await member.dispose()
		}
	})
})
