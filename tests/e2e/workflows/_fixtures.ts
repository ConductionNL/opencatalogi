import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
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
import { request } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'

// ⚠️ This module CREATES AND DELETES catalogs and publications. Its previous
// `|| 'http://localhost:8080'` fallback aimed those writes at the SHARED dev
// container whenever NEXTCLOUD_URL was unset — which is the default. There is
// deliberately no default now; see tests/e2e/base-url.ts.
export const BASE = BASE_URL
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
export let SCHEMA_USAGE_COUNTER: number | string = 56

/**
 * The register the MANIFEST reads, which is not always the one above.
 *
 * `lib/Settings/publication_register.json` imports under slug `opencatalogi`,
 * and every manifest widget names `"register": "opencatalogi"`. A dev box that
 * has also seen the older demo import carries a SECOND register under slug
 * `publication`, and objects are stored per (register, schema): a fixture
 * written to one is invisible to a page reading the other. The deep CRUD specs
 * write and read through the same ids, so they never noticed. A fixture whose
 * point is to put a number on a manifest-driven report does notice, so it
 * resolves this one instead.
 */
export let REG_OPENCATALOGI: number | string = 14

/** Stable slugs the OpenCatalogi register import uses (slug -> resolved id). */
const REGISTER_SLUG = 'publication'
const MANIFEST_REGISTER_SLUG = 'opencatalogi'
const SCHEMA_SLUGS = {
	publication: 'publication',
	catalog: 'catalog',
	usageCounter: 'usageCounter',
}

function OBJ(reg: number | string, schema: number | string, id?: string) {
	return `/index.php/apps/openregister/api/objects/${reg}/${schema}${id ? `/${id}` : ''}`
}

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
	private created: Array<{
		register: number | string
		schema: number | string
		id: string
	}> = []

	constructor(runId = newRunId()) {
		this.runId = runId
		this.prefix = `e2e-${runId}`
	}

	/** Open a basic-auth request context (no CSRF needed; admin:admin). */
	async init(): Promise<void> {
		this.ctx = await request.newContext({
			baseURL: BASE,
			httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
			extraHTTPHeaders: {
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			},
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
		const regRes = await this.ctx.get(
			'/index.php/apps/openregister/api/registers?_limit=300',
		)
		if (regRes.ok()) {
			const body = await regRes.json()
			const list = Array.isArray(body) ? body : body.results || []
			const reg = list.find(
				(r: Record<string, unknown>) => r.slug === REGISTER_SLUG,
			)
			if (reg && (reg.id || reg.id === 0))
				REG_PUBLICATION = reg.id as number | string
			const manifestReg = list.find(
				(r: Record<string, unknown>) => r.slug === MANIFEST_REGISTER_SLUG,
			)
			// Fall back to the publication register rather than to a
			// hardcoded id: on an instance with only one of the two, that is
			// the same register, and on an instance with neither the failure
			// belongs at the create call, not at a number that happens to
			// name some other app's register.
			REG_OPENCATALOGI =
				manifestReg && (manifestReg.id || manifestReg.id === 0)
					? (manifestReg.id as number | string)
					: REG_PUBLICATION
		}
		// A PAGE IS NOT THE SET, and a SLUG IS NOT UNIQUE. Both bit here.
		//
		// This asked for `?_limit=1000` and treated the page as everything.
		// On an instance running the whole fleet there are 1,876 schemas, so
		// `catalog` and `publication` fell off the end, `bySlug` never held
		// them, and the hardcoded fallbacks below stood — pointing at
		// whichever app owns ids 53 and 54 on that instance. The suite then
		// failed with `create 18/53 failed: The required properties (term,
		// definition, portal) are missing`, which is a GLOSSARY complaining
		// about a publication write, and reads as a data bug rather than a
		// resolution one.
		//
		// Resolving inside THIS app's register fixes both at once: the
		// register names its own schema ids, so the set is small, complete,
		// and cannot contain another app's same-named schema.
		const resolved = await this.schemasOfRegister(REG_PUBLICATION)
		if (resolved.has(SCHEMA_SLUGS.publication))
			SCHEMA_PUBLICATION = resolved.get(SCHEMA_SLUGS.publication)!
		if (resolved.has(SCHEMA_SLUGS.catalog))
			SCHEMA_CATALOG = resolved.get(SCHEMA_SLUGS.catalog)!
		if (resolved.has(SCHEMA_SLUGS.usageCounter))
			SCHEMA_USAGE_COUNTER = resolved.get(SCHEMA_SLUGS.usageCounter)!

		// FAIL HERE, not at the create. A slug that did not resolve leaves a
		// hardcoded id in place, and that id belongs to somebody. Better to
		// say which slug is missing than to write a publication into another
		// app's glossary and report its validation error.
		const unresolved = [SCHEMA_SLUGS.publication, SCHEMA_SLUGS.catalog].filter(
			(slug) => !resolved.has(slug),
		)
		if (unresolved.length > 0) {
			throw new Error(
				`Fixtures.init(): register "${REGISTER_SLUG}" (id ${REG_PUBLICATION}) `
					+ `does not carry ${unresolved.join(', ')}. `
					+ 'Run tests/e2e/ci-seed.sh against this instance first.',
			)
		}
	}

	/**
	 * The slug -> id map for the schemas a register actually carries.
	 *
	 * Reads the register's own `schemas` list and resolves each id, rather than
	 * listing every schema on the instance and filtering by slug. On a fleet
	 * instance that list runs to thousands and a slug is not unique across apps,
	 * so filtering by slug is both paginated and ambiguous.
	 *
	 * @param register The register id or slug.
	 *
	 * @return Slug to schema id, for that register only.
	 */
	private async schemasOfRegister(
		register: number | string,
	): Promise<Map<string, number | string>> {
		const out = new Map<string, number | string>()

		const res = await this.ctx.get(
			`/index.php/apps/openregister/api/registers/${register}`,
		)
		if (!res.ok()) return out

		const body = await res.json()
		const ids: Array<number | string> = body?.schemas ?? []

		for (const id of ids) {
			const schemaRes = await this.ctx.get(
				`/index.php/apps/openregister/api/schemas/${id}`,
			)
			if (!schemaRes.ok()) continue
			const schema = await schemaRes.json()
			if (schema?.slug) out.set(String(schema.slug), schema.id ?? id)
		}

		return out
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
			throw new Error(
				`create ${register}/${schema} failed: ${res.status()} ${await res.text()}`,
			)
		}
		const body = await res.json()
		const id = (body.id as string) || (body['@self']?.id as string)
		if (!id)
			throw new Error(
				`create ${register}/${schema} returned no id: ${JSON.stringify(body)}`,
			)
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
	async createCatalog(
		name: string,
		extra: Record<string, unknown> = {},
	): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(REG_PUBLICATION, SCHEMA_CATALOG, {
			title,
			summary: `Fixture catalog for ${this.prefix}`,
			description: 'Created by the OpenCatalogi deep e2e workflow suite.',
			// Wire the catalog at the publication register + publication schema so
			// publications created in REG_PUBLICATION/SCHEMA_PUBLICATION surface
			// through this catalog's public listing. A caller that also needs the
			// document schema in scope passes it through `extra.schemas`; widening
			// the default breaks catalog-crud-persistence, which asserts this exact
			// list round-trips.
			registers: [REG_PUBLICATION],
			schemas: [SCHEMA_PUBLICATION],
			listed: true,
			// LISTED IS NOT ENOUGH, AND THAT IS THE PART THAT BITES.
			// `resolveCatalogScope()` unions only listed AND published catalogs, and
			// `isCatalogPubliclyAvailable()` requires `published` to be a non-empty
			// string parsing to a date <= now. Without it every catalog this fixture
			// creates is dropped from that union silently, so it contributes nothing
			// to what /api/search can see — which is never what a test asking for a
			// catalog wants.
			published: '2020-01-01T00:00:00+00:00',
			...extra,
		})
	}

	/**
	 * Create a Publication (in draft — no publish action applied).
	 * @param name
	 * @param extra
	 * @param register Which register to create it in. Defaults to the deep
	 *                 suite's register; a caller seeding a manifest-driven page
	 *                 passes {@link REG_OPENCATALOGI} so the page can see it.
	 */
	async createPublication(
		name: string,
		extra: Record<string, unknown> = {},
		register: number | string = REG_PUBLICATION,
	): Promise<SeededObject> {
		const title = this.label(name)
		return this.create(register, SCHEMA_PUBLICATION, {
			title,
			summary: `Fixture publication for ${this.prefix}`,
			description: 'Created by the OpenCatalogi deep e2e workflow suite.',
			...extra,
		})
	}

	/**
	 * Create a usageCounter row.
	 *
	 * usageCounter stores a `count` per publication per day per kind, so the
	 * Usage report SUMs `count` rather than counting rows. A test that wants a
	 * number on that report has to put one there: with no counters the report
	 * correctly renders an em-dash, and an assertion for a digit then measures
	 * whether some earlier spec happened to leave data behind.
	 *
	 * The `organization` and `document` creators that used to sit here were
	 * removed with their schemas (`catalogi-uses-the-shared-organisation` and
	 * `attachments-are-files`). Their hardcoded fallback ids were the hazard:
	 * schema slugs are global per organisation, so once the slug stopped
	 * resolving here the fixture would have written into whichever app owns id
	 * 47 or 55 on that instance.
	 *
	 * @param publicationId The publication UUID the counter is attributed to.
	 * @param kind          `view` or `download`.
	 * @param count         How many, on that day.
	 * @param extra         Extra fields merged into the object.
	 */
	async createUsageCounter(
		publicationId: string,
		kind: 'view' | 'download',
		count: number,
		extra: Record<string, unknown> = {},
	): Promise<SeededObject> {
		return this.create(REG_OPENCATALOGI, SCHEMA_USAGE_COUNTER, {
			publication: publicationId,
			kind,
			count,
			date: new Date().toISOString().slice(0, 10),
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
			throw new Error(
				`attachFile ${register}/${schema}/${id} failed: ${res.status()} ${await res.text()}`,
			)
		}
		const body = await res.json()
		const fileId = body.id as number
		if (!fileId)
			throw new Error(
				`attachFile returned no file id: ${JSON.stringify(body)}`,
			)
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
				{ cause: err },
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
	async remove(
		register: number | string,
		schema: number | string,
		id: string,
	): Promise<void> {
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
		// usageCounter is deliberately absent: it carries no `title` to match a
		// prefix on, and it lives in REG_OPENCATALOGI rather than here. The
		// tracked-id pass above is what removes it.
		for (const schema of [SCHEMA_PUBLICATION, SCHEMA_CATALOG]) {
			const rows = await this.list(REG_PUBLICATION, schema, 500)
			for (const row of rows) {
				const title =
					(row.title as string)
					?? (row['@self'] as Record<string, unknown>)?.name
					?? ''
				if (typeof title === 'string' && title.startsWith(this.prefix)) {
					const id =
						(row.id as string)
						|| ((row['@self'] as Record<string, unknown>)?.id as string)
					if (id)
						await this.ctx
							.delete(OBJ(REG_PUBLICATION, schema, id))
							.catch(() => {})
				}
			}
		}
	}

	async dispose(): Promise<void> {
		await this.ctx.dispose()
	}
}
