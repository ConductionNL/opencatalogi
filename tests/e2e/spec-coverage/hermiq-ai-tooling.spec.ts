/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Governed agent writes through OpenRegister's MCP server (hermiq-ai-tooling task 5).
 *
 * The tools are called the way an agent calls them: JSON-RPC on
 * /apps/openregister/api/mcp, after an `initialize` that hands out the
 * Mcp-Session-Id. The human steps run through the same APIs the screens use:
 * the lifecycle's own transitions, and OpenRegister's task inbox.
 *
 * @e2e openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-publish-depublish-and-archive-execute-only-after-a-server-verified-human-approval-req-009 REQ-009
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '../authenticated-request.ts'
import {
	Fixtures,
	REG_PUBLICATION,
	SCHEMA_PUBLICATION,
} from '../workflows/_fixtures.ts'

const MCP = '/index.php/apps/openregister/api/mcp'
const TASKS = '/index.php/apps/openregister/api/flow-tasks'
const API_BASE = '/index.php/apps/opencatalogi/api'

/** An MCP session: initialize once, then call tools with its session id. */
class McpSession {
	private sessionId = ''
	private nextId = 1

	constructor(private readonly request: APIRequestContext) {}

	private headers(): Record<string, string> {
		const headers: Record<string, string> = {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		}
		if (this.sessionId !== '') headers['Mcp-Session-Id'] = this.sessionId
		return headers
	}

	async init(): Promise<void> {
		const res = await this.request.post(MCP, {
			headers: this.headers(),
			data: {
				jsonrpc: '2.0',
				id: 0,
				method: 'initialize',
				params: {
					protocolVersion: '2025-06-18',
					capabilities: {},
					clientInfo: { name: 'opencatalogi-e2e', version: '1' },
				},
			},
		})
		expect(res.ok(), `initialize: ${await res.text()}`).toBe(true)
		this.sessionId = res.headers()['mcp-session-id'] ?? ''
		expect(this.sessionId, 'the server hands out a session id').not.toBe('')
	}

	async rpc(method: string, params: Record<string, unknown> = {}): Promise<Record<string, unknown>> {
		const res = await this.request.post(MCP, {
			headers: this.headers(),
			data: { jsonrpc: '2.0', id: this.nextId++, method, params },
		})
		const body = await res.json()
		expect(body.error, `${method}: ${JSON.stringify(body.error)}`).toBeUndefined()
		return body.result as Record<string, unknown>
	}

	/** Call a tool and answer its structured result (the JSON text of its first content block). */
	async call(name: string, args: Record<string, unknown>): Promise<Record<string, any>> {
		const result = await this.rpc('tools/call', { name, arguments: args })
		const structured = (result as { structuredContent?: Record<string, any> }).structuredContent
		if (structured !== undefined) return structured
		const content = (result as { content?: Array<{ text?: string }> }).content ?? []
		return JSON.parse(content[0]?.text ?? '{}')
	}
}

test.describe('Governed agent writes (hermiq-ai-tooling)', () => {
	test.setTimeout(120_000)
	const fx = new Fixtures()
	let mcp: McpSession

	test.beforeAll(async () => {
		await fx.init()
	})

	test.beforeEach(async ({ request }) => {
		mcp = new McpSession(request)
		await mcp.init()
	})

	test.afterAll(async () => {
		await fx.cleanupAll()
		await fx.dispose()
	})

	test('the four write tools are served, and depublish says it writes nothing', async () => {
		const tools = ((await mcp.rpc('tools/list')).tools ?? []) as Array<{ name: string; description: string }>
		const names = tools.map((tool) => tool.name)
		for (const name of ['createDraftPublication', 'publishPublication', 'depublishPublication', 'archivePublication']) {
			expect(names, name).toContain(name)
		}
		const depublish = tools.find((tool) => tool.name === 'depublishPublication')
		expect(depublish?.description).toContain('does not withdraw anything')
	})

	test('an agent drafts, publishes only what a person approved, and a draft stays private', async ({ request }) => {
		const draft = await mcp.call('createDraftPublication', {
			publication: {
				title: `${fx.label('agent draft')}`,
				summary: 'Door een agent opgesteld',
				description: 'Concept dat een agent opstelde.',
			},
		})
		expect(draft.success, JSON.stringify(draft)).toBe(true)
		const draftId = String(draft.publication.id)
		expect(draft.publication.status).toBe('draft')

		const refused = await mcp.call('publishPublication', { ids: [draftId] })
		expect(refused.refused).toEqual([{ id: draftId, code: 'not_approved' }])
		const draftState = await request.get(`${API_BASE}/publications/${draftId}/visibility`)
		expect((await draftState.json()).state, 'the draft is not public').toBe('draft')

		// A person approved this one; it has a document, so the publish button's checks pass.
		const approved = await fx.createPublication('agent approved', { status: 'approved' })
		await fx.attachFile(REG_PUBLICATION, SCHEMA_PUBLICATION, approved.id, 'besluit.txt', 'Het besluit.')
		const published = await mcp.call('publishPublication', { ids: [approved.id] })
		expect(published.published, JSON.stringify(published)).toEqual([approved.id])

		const visibility = await request.get(`${API_BASE}/publications/${approved.id}/visibility`)
		expect((await visibility.json()).state).toBe('public')
	})

	test('a withdrawal by an agent waits for a publisher', async ({ request }) => {
		const publication = await fx.createPublication('agent withdraw', {
			status: 'published',
			publicationDate: '2026-01-05T09:00:00+00:00',
		})

		const staged = await mcp.call('depublishPublication', {
			ids: [publication.id],
			reason: 'Rechterlijke uitspraak',
		})
		expect(staged.staged?.[0]?.id, JSON.stringify(staged)).toBe(publication.id)
		const taskId = String(staged.staged[0].task)

		const before = await request.get(`${API_BASE}/publications/${publication.id}/visibility`)
		expect((await before.json()).state, 'the publication stays public until a publisher acts').toBe('public')

		const task = await (await request.get(`${TASKS}/${taskId}`)).json()
		expect((task.task ?? task).candidateGroups).toEqual(['opencatalogi-publishers'])

		expect((await request.post(`${TASKS}/${taskId}/claim`)).ok()).toBe(true)
		const completed = await request.post(`${TASKS}/${taskId}/complete`, { data: {} })
		expect(completed.ok(), `complete: ${await completed.text()}`).toBe(true)

		await expect
			.poll(async () => (await (await request.get(`${API_BASE}/publications/${publication.id}/visibility`)).json()).state)
			.toBe('withdrawn')
	})

	test('an archive request for a draft is refused and writes nothing', async ({ request }) => {
		const draft = await fx.createPublication('agent archive draft')
		const answer = await mcp.call('archivePublication', { ids: [draft.id], reason: 'Bewaartermijn' })
		expect(answer.refused).toEqual([{ id: draft.id, code: 'not_published' }])

		const visibility = await request.get(`${API_BASE}/publications/${draft.id}/visibility`)
		expect((await visibility.json()).state).toBe('draft')
	})
})
