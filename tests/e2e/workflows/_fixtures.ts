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

/**
 * Publication register + the schemas it carries.
 *
 * The NUMERIC ids vary between environments (dev box = register 14, a fresh CI
 * boot re-imports the register by slug and gets a different id). These are seeded
 * with the dev-box values and RESOLVED FROM STABLE SLUGS at runtime by
 * Fixtures.init() (GET /openregister/api/registers + .../schemas), so the deep
 * suite survives a fresh CI boot. They are `let`, not `const`, because init()
 * reassigns them once the live ids are known; every spec reads them inside its
 * test body (after init()), so the resolved value is what gets used.
 */
export let REG_PUBLICATION: number | string = 14
export let SCHEMA_PUBLICATION: number | string = 53
export let SCHEMA_CATALOG: number | string = 54
export let SCHEMA_ORGANIZATION: number | string = 47
export let SCHEMA_DOCUMENT: number | string = 55

/** Stable slugs the OpenCatalogi register import uses (slug -> resolved id). */
const REGISTER_SLUG = 'publication'
const SCHEMA_SLUGS = {
	publication: 'publication',
	catalog: 'catalog',
	organization: 'organization',
	document: 'document',
}

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
		await this.resolveRegisterAndSchemas()
	}

	/**
	 * Resolve the numeric register + schema ids from their stable slugs so the
	 * suite is portable to a fresh CI boot where the re-imported ids differ.
	 * Best-effort: on any failure the dev-box seed ids are kept, so a transient
	 * registers/schemas hiccup never breaks the suite harder than the original
	 * hardcoded ids would have.
	 */
	private async resolveRegisterAndSchemas(): Promise<void> {
		try {
			const regRes = await this.ctx.get('/index.php/apps/openregister/api/registers?_limit=300')
			if (regRes.ok()) {
				const body = await regRes.json()
				const list = Array.isArray(body) ? body : (body.results || [])
				const reg = list.find((r: Record<string, unknown>) => r.slug === REGISTER_SLUG)
				if (reg && (reg.id || reg.id === 0)) REG_PUBLICATION = reg.id as number | string
			}
			const schRes = await this.ctx.get('/index.php/apps/openregister/api/schemas?_limit=1000')
			if (schRes.ok()) {
				const body = await schRes.json()
				const list = Array.isArray(body) ? body : (body.results || [])
				const bySlug = new Map<string, number | string>()
				for (const s of list) if (s && s.slug) bySlug.set(s.slug as string, s.id as number | string)
				if (bySlug.has(SCHEMA_SLUGS.publication)) SCHEMA_PUBLICATION = bySlug.get(SCHEMA_SLUGS.publication)!
				if (bySlug.has(SCHEMA_SLUGS.catalog)) SCHEMA_CATALOG = bySlug.get(SCHEMA_SLUGS.catalog)!
				if (bySlug.has(SCHEMA_SLUGS.organization)) SCHEMA_ORGANIZATION = bySlug.get(SCHEMA_SLUGS.organization)!
				if (bySlug.has(SCHEMA_SLUGS.document)) SCHEMA_DOCUMENT = bySlug.get(SCHEMA_SLUGS.document)!
			}
		} catch {
			/* keep dev-box seed ids on any resolution failure */
		}
	}

	get api(): APIRequestContext {
		return this.ctx
	}

	/**
	 * Label every fixture with the run prefix so a sweep can find it.
	 * @param name
	 */
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

	/**
	 * GET one object back (used to assert backend persistence).
	 * @param register
	 * @param schema
	 * @param id
	 */
	async fetch(
		register: number | string,
		schema: number | string,
		id: string,
	): Promise<Record<string, unknown> | null> {
		const res = await this.ctx.get(OBJ(register, schema, id))
		if (!res.ok()) return null
		return res.json()
	}

	/**
	 * List objects, optionally limited.
	 * @param register
	 * @param schema
	 * @param limit
	 */
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

	/**
	 * Create a Catalog wired to the publication register+schema.
	 * @param name
	 * @param extra
	 */
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

	/**
	 * Create a Publication (in draft — no publish action applied).
	 * @param name
	 * @param extra
	 */
	async createPublication(name: string, extra: Record<string, unknown> = {}): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(REG_PUBLICATION, SCHEMA_PUBLICATION, {
			title,
			summary: `Fixture publication for ${this.prefix}`,
			description: 'Created by the OpenCatalogi deep e2e workflow suite.',
			...extra,
		})
	}

	/**
	 * Create an Organization.
	 * @param name
	 * @param extra
	 */
	async createOrganization(name: string, extra: Record<string, unknown> = {}): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(REG_PUBLICATION, SCHEMA_ORGANIZATION, {
			title,
			summary: `Fixture organization for ${this.prefix}`,
			...extra,
		})
	}

	/**
	 * Create a Document linked to a publication (WOO-517 content-search fixture).
	 * `publication` carries the `{slug, title}` summary
	 * `PublicationQueryService::resolveDocumentPublicationSummary()` resolves by
	 * slug — the UUID is filled in server-side once the link is followed.
	 * @param name
	 * @param publication
	 * @param publication.slug
	 * @param publication.title
	 * @param extra
	 */
	async createDocument(
		name: string,
		publication: { slug: string; title: string },
		extra: Record<string, unknown> = {},
	): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(REG_PUBLICATION, SCHEMA_DOCUMENT, {
			title,
			summary: `Fixture document for ${this.prefix}`,
			publication,
			...extra,
		})
	}

	/**
	 * Attach a small text file to an already-created object via OpenRegister's
	 * generic file-attach endpoint (`POST .../objects/{register}/{schema}/{id}/files`).
	 * Returns the Nextcloud file id (`formatFile()`'s `id` field), needed to
	 * force-trigger extraction via {@see extractFile}.
	 * @param register
	 * @param schema
	 * @param id
	 * @param fileName
	 * @param content
	 */
	async attachFile(
		register: number | string,
		schema: number | string,
		id: string,
		fileName: string,
		content: string,
	): Promise<number> {
		const res = await this.ctx.post(`${OBJ(register, schema, id)}/files`, {
			data: { name: fileName, content },
		})
		if (!res.ok()) {
			throw new Error(`attachFile ${register}/${schema}/${id} failed: ${res.status()} ${await res.text()}`)
		}
		const body = await res.json()
		const fileId = body.id as number
		if (!fileId) throw new Error(`attachFile returned no file id: ${JSON.stringify(body)}`)
		return fileId
	}

	/**
	 * Force-trigger OR's text-extraction for one file rather than waiting for the
	 * lazy `FileTextExtractionJob` cron (`POST /apps/openregister/api/files/{id}/extract`).
	 *
	 * Distinguishes hard failures (404 endpoint moved, 401/403 auth broken) from
	 * transient hiccups. On a hard failure the fixture throws immediately so the
	 * test fails fast with the real root cause, not a misleading "marker did not
	 * surface" 10-second poll timeout. Transient statuses (409 already-running,
	 * 5xx) are logged and swallowed so the poll loop downstream can still succeed
	 * if the extraction was already scheduled.
	 * @param fileId
	 */
	async extractFile(fileId: number): Promise<void> {
		let res
		try {
			res = await this.ctx.post(
				`/index.php/apps/openregister/api/files/${fileId}/extract`,
				{ data: { forceReExtract: true } },
			)
		} catch (err) {
			throw new Error(
				`extractFile ${fileId}: network failure — ${(err as Error).message}`,
			)
		}
		if (res.ok()) {
			return
		}
		const status = res.status()
		if (status === 404 || status === 401 || status === 403) {
			const body = await res.text().catch(() => '')
			throw new Error(
				`extractFile ${fileId}: hard failure (${status}) — endpoint moved or auth broken. Body: ${body.slice(0, 200)}`,
			)
		}
		// 409 (already running), 5xx (transient) — log and let the poll loop decide.
		const body = await res.text().catch(() => '')
		console.warn(
			`[fixtures] extractFile ${fileId}: transient status ${status}, continuing. Body: ${body.slice(0, 200)}`,
		)
	}

	/**
	 * Delete a single created object (by id) and forget it.
	 * @param register
	 * @param schema
	 * @param id
	 */
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

		// Prefix sweep across the fixture schemas (catches UI-created rows).
		for (const schema of [SCHEMA_PUBLICATION, SCHEMA_CATALOG, SCHEMA_ORGANIZATION, SCHEMA_DOCUMENT]) {
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
