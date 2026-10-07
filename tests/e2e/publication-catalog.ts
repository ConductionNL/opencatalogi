/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A catalog whose publications page lists the publication register + schema.
 *
 * The page lists a catalog's numeric `registers` × `schemas`, so a catalog
 * without them shows an empty state instead of the index. Tests that need the
 * publications index pick a catalog scoped to the publication pair, or seed
 * one, the way workflows/_fixtures.ts wires its catalogs.
 */
import type { APIRequestContext } from '@playwright/test'

import { expect } from '@playwright/test'

const OR_API = '/index.php/apps/openregister/api'

/** The publication register and schema ids. */
export interface PublicationScope {
	register: number
	schema: number
}

/** A catalog the publications tests can open. */
export interface PublicationCatalog {
	id: string
	slug: string
	/** In-app route to its publications, on the publication pair even when the catalog has several. */
	path: string
}

/**
 * The publications route of a catalog, on the publication pair.
 *
 * @param slug The catalog slug.
 * @param scope The publication register and schema ids.
 */
function publicationsPath(slug: string, scope: PublicationScope): string {
	return `/publications/${encodeURIComponent(slug)}?_pair=${scope.register}-${scope.schema}`
}

/**
 * Resolve the publication register by slug, and the publication schema among
 * the schemas that register carries (a schema slug is not unique across apps).
 *
 * @param request An authenticated API request context.
 */
export async function publicationScope(
	request: APIRequestContext,
): Promise<PublicationScope> {
	const registerRes = await request.get(`${OR_API}/registers/publication`)
	expect(registerRes.ok(), 'the publication register resolves').toBe(true)
	const register = await registerRes.json()
	const schemaIds: unknown[] = Array.isArray(register?.schemas)
		? register.schemas
		: []
	for (const entry of schemaIds) {
		const id =
			entry && typeof entry === 'object'
				? (entry as Record<string, unknown>).id
				: entry
		const schemaRes = await request.get(`${OR_API}/schemas/${id}`)
		if (!schemaRes.ok()) continue
		const schema = await schemaRes.json()
		if (schema?.slug === 'publication') {
			return { register: Number(register.id), schema: Number(schema.id) }
		}
	}
	throw new Error('The publication register carries no publication schema.')
}

/**
 * Whether a catalog's id list holds this id, as a number or numeric string.
 *
 * @param list The catalog's `registers` or `schemas`.
 * @param id The id.
 */
function holds(list: unknown, id: number): boolean {
	return (
		Array.isArray(list)
		&& list.some((value) => String(value).trim() === String(id))
	)
}

/**
 * A catalog scoped to the publication register + schema: an existing one from
 * the public catalog list, else the one with the seed slug, else one seeded
 * through OpenRegister.
 *
 * @param request An authenticated API request context.
 * @param runId Prefix for the seeded catalog's slug and title; a fixed value reuses the same catalog on every run.
 */
export async function publicationCatalog(
	request: APIRequestContext,
	runId: string,
): Promise<PublicationCatalog> {
	const scope = await publicationScope(request)

	const list = await request.get('/index.php/apps/opencatalogi/api/catalogi')
	expect(list.status(), 'GET /api/catalogi must succeed').toBe(200)
	const body = await list.json()
	const results: Array<Record<string, any>> = Array.isArray(body)
		? body
		: (body?.results ?? [])
	const existing = results.find(
		(catalog) =>
			(catalog?.slug || catalog?.['@self']?.slug)
			&& holds(catalog.registers, scope.register)
			&& holds(catalog.schemas, scope.schema),
	)
	if (existing) {
		const slug = String(existing.slug ?? existing['@self']?.slug)
		return {
			id: String(existing['@self']?.id ?? existing.id ?? existing.uuid),
			slug,
			path: publicationsPath(slug, scope),
		}
	}

	const settingsRes = await request.get(
		'/index.php/apps/opencatalogi/api/settings',
	)
	expect(settingsRes.status(), 'GET /api/settings must succeed').toBe(200)
	const settings = await settingsRes.json()
	const catalogRegister =
		settings?.configuration?.catalog_register
		?? settings?.catalog_register
		?? 'publication'
	const catalogSchema =
		settings?.configuration?.catalog_schema
		?? settings?.catalog_schema
		?? 'catalog'

	const slug = `${runId}-cat`
	const seededRes = await request.get(
		`${OR_API}/objects/${catalogRegister}/${catalogSchema}`
			+ `?slug=${encodeURIComponent(slug)}&_source=database`,
	)
	if (seededRes.ok()) {
		const seededBody = await seededRes.json()
		const seeded = (seededBody?.results ?? []).find(
			(catalog: Record<string, any>) => catalog?.slug === slug,
		)
		if (seeded) {
			return {
				id: String(seeded['@self']?.id ?? seeded.id ?? seeded.uuid),
				slug,
				path: publicationsPath(slug, scope),
			}
		}
	}

	const created = await request.post(
		`${OR_API}/objects/${catalogRegister}/${catalogSchema}`,
		{
			data: {
				title: `${runId} catalog`,
				summary: 'Seeded publication catalog',
				slug,
				listed: true,
				registers: [scope.register],
				schemas: [scope.schema],
			},
			headers: { 'Content-Type': 'application/json' },
		},
	)
	expect(
		created.status(),
		'seeding a publication catalog must succeed',
	).toBeLessThan(300)
	const obj = await created.json()
	return {
		id: String(obj?.['@self']?.id ?? obj?.id ?? obj?.uuid),
		slug,
		path: publicationsPath(slug, scope),
	}
}
