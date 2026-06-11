/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Seeded-fixture helpers for the DEEP, data-dependent OpenCatalogi e2e
 * workflow suite.
 *
 * These create and tear down real OpenRegister objects (Catalogs,
 * Publications, Organizations) through the OpenRegister object REST API —
 * the SAME API the OpenCatalogi frontend stores call:
 *
 *   POST   /index.php/apps/openregister/api/objects/{register}/{schema}
 *   GET    /index.php/apps/openregister/api/objects/{register}/{schema}[/{id}]
 *   PUT    /index.php/apps/openregister/api/objects/{register}/{schema}/{id}
 *   DELETE /index.php/apps/openregister/api/objects/{register}/{schema}/{id}
 *
 * Those write verbs are `@NoCSRFRequired` on OpenRegister's ObjectsController,
 * so a basic-auth APIRequestContext (admin:admin) drives them without a CSRF
 * token. We deliberately use a fresh request context rather than the browser
 * session so fixture setup is independent of the SPA boot.
 *
 * Register / schema IDs are resolved at runtime from the OpenCatalogi app
 * config (oc_appconfig) shape, falling back to the known dev-container
 * values. In this dev container:
 *   - Publication register = 14 (slug "publication")
 *   - publication schema   = 53
 *   - catalog schema       = 54
 *   - organization schema  = 47
 *
 * Every fixture name carries a unique `e2e-<runId>` prefix so a failed run
 * never collides with the next, and `cleanupAll()` (called from afterAll)
 * deletes everything this run created — by tracked id, and as a safety net by
 * prefix sweep.
 *
 * NOTE on OR API verbs: this helper only uses verbs that genuinely exist on
 * the OpenRegister ObjectsController (index/show/create/update/destroy).
 * There is NO per-object publish/depublish route in this OpenRegister build
 * (see _fixtures `setPublished` note and the publish-workflow spec) — so we
 * never call a non-existent `saveObject`/`publish` REST verb here.
 */
import { request, type APIRequestContext } from '@playwright/test'

export const BASE = process.env.NEXTCLOUD_URL || 'http://localhost:8080'
export const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
export const ADMIN_PASS = process.env.NC_ADMIN_PASS || 'admin'

/** Publication register + the schemas it carries, in this dev container. */
export const REG_PUBLICATION = 14
export const SCHEMA_PUBLICATION = 53
export const SCHEMA_CATALOG = 54
export const SCHEMA_ORGANIZATION = 47

const OBJ = (reg: number | string, schema: number | string, id?: string) =>
	`/index.php/apps/openregister/api/objects/${reg}/${schema}${id ? `/${id}` : ''}`

/** A unique-per-run prefix so fixtures never collide and are easy to sweep. */
export function newRunId(): string {
	return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

export interface SeededObject {
	id: string
	register: number | string
	schema: number | string
	title: string
	raw: Record<string, unknown>
}

export class Fixtures {
	readonly runId: string
	readonly prefix: string
	private ctx!: APIRequestContext
	private created: Array<{ register: number | string; schema: number | string; id: string }> = []

	constructor(runId = newRunId()) {
		this.runId = runId
		this.prefix = `e2e-${runId}`
	}

	/** Open a basic-auth request context (no CSRF needed; admin:admin). */
	async init(): Promise<void> {
		this.ctx = await request.newContext({
			baseURL: BASE,
			httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
			extraHTTPHeaders: { 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
		})
	}

	get api(): APIRequestContext {
		return this.ctx
	}

	/** Label every fixture with the run prefix so a sweep can find it. */
	label(name: string): string {
		return `${this.prefix} ${name}`
	}

	private async create(
		register: number | string,
		schema: number | string,
		data: Record<string, unknown>,
	): Promise<SeededObject> {
		const res = await this.ctx.post(OBJ(register, schema), { data })
		if (!res.ok()) {
			throw new Error(`create ${register}/${schema} failed: ${res.status()} ${await res.text()}`)
		}
		const body = await res.json()
		const id = (body.id as string) || (body['@self']?.id as string)
		if (!id) throw new Error(`create ${register}/${schema} returned no id: ${JSON.stringify(body)}`)
		this.created.push({ register, schema, id })
		return {
			id,
			register,
			schema,
			title: (body.title as string) ?? '',
			raw: body,
		}
	}

	/** GET one object back (used to assert backend persistence). */
	async fetch(
		register: number | string,
		schema: number | string,
		id: string,
	): Promise<Record<string, unknown> | null> {
		const res = await this.ctx.get(OBJ(register, schema, id))
		if (!res.ok()) return null
		return res.json()
	}

	/** List objects, optionally limited. */
	async list(
		register: number | string,
		schema: number | string,
		limit = 200,
	): Promise<Array<Record<string, unknown>>> {
		const res = await this.ctx.get(`${OBJ(register, schema)}?_limit=${limit}`)
		if (!res.ok()) return []
		const body = await res.json()
		return (body.results as Array<Record<string, unknown>>) ?? []
	}

	/** Create a Catalog wired to the publication register+schema. */
	async createCatalog(name: string, extra: Record<string, unknown> = {}): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(REG_PUBLICATION, SCHEMA_CATALOG, {
			title,
			summary: `Fixture catalog for ${this.prefix}`,
			description: 'Created by the OpenCatalogi deep e2e workflow suite.',
			// Wire the catalog at the publication register + publication schema so
			// publications created in REG_PUBLICATION/SCHEMA_PUBLICATION surface
			// through this catalog's public listing.
			registers: [REG_PUBLICATION],
			schemas: [SCHEMA_PUBLICATION],
			listed: true,
			...extra,
		})
	}

	/** Create a Publication (in draft — no publish action applied). */
	async createPublication(name: string, extra: Record<string, unknown> = {}): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(REG_PUBLICATION, SCHEMA_PUBLICATION, {
			title,
			summary: `Fixture publication for ${this.prefix}`,
			description: 'Created by the OpenCatalogi deep e2e workflow suite.',
			...extra,
		})
	}

	/** Create an Organization. */
	async createOrganization(name: string, extra: Record<string, unknown> = {}): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(REG_PUBLICATION, SCHEMA_ORGANIZATION, {
			title,
			summary: `Fixture organization for ${this.prefix}`,
			...extra,
		})
	}

	/** Delete a single created object (by id) and forget it. */
	async remove(register: number | string, schema: number | string, id: string): Promise<void> {
		await this.ctx.delete(OBJ(register, schema, id)).catch(() => {})
		this.created = this.created.filter((c) => !(c.id === id))
	}

	/**
	 * Delete everything this run created, then sweep any straggler whose title
	 * still carries this run's prefix (covers objects created via the UI).
	 */
	async cleanupAll(): Promise<void> {
		// Tracked ids first.
		for (const c of [...this.created].reverse()) {
			await this.ctx.delete(OBJ(c.register, c.schema, c.id)).catch(() => {})
		}
		this.created = []

		// Prefix sweep across the three fixture schemas (catches UI-created rows).
		for (const schema of [SCHEMA_PUBLICATION, SCHEMA_CATALOG, SCHEMA_ORGANIZATION]) {
			const rows = await this.list(REG_PUBLICATION, schema, 500)
			for (const row of rows) {
				const title = (row.title as string) ?? (row['@self'] as Record<string, unknown>)?.name ?? ''
				if (typeof title === 'string' && title.startsWith(this.prefix)) {
					const id = (row.id as string) || ((row['@self'] as Record<string, unknown>)?.id as string)
					if (id) await this.ctx.delete(OBJ(REG_PUBLICATION, schema, id)).catch(() => {})
				}
			}
		}
	}

	async dispose(): Promise<void> {
		await this.ctx.dispose()
	}
}
