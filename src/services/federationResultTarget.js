/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a click on a federation search result leads. A local result routes to
 * PublicationDetail inside the app; a federated result routes to
 * FederatedPublication inside the app, which reads it from its source and
 * links to the source's own page.
 */

/**
 * The default OpenCatalogi slug for a catalog title: lower case, every run of
 * other characters replaced by one dash, no leading or trailing dashes.
 *
 * @param {string|number} title The catalog title.
 * @return {string} The slug, possibly empty.
 * @spec exclude presentation-only click-through
 */
export function catalogSlugFromTitle(title) {
	return String(title)
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

/**
 * The catalog in the local collection whose id or `@self.id` equals `id`.
 *
 * @param {string|number} id The catalog id from the result.
 * @param {() => (Array<object>|{results: Array<object>}|null)} getCatalogs Returns the local catalog collection (an array or `{results}`).
 * @return {object|undefined} The matching catalog.
 */
function findCatalogById(id, getCatalogs) {
	const collection = getCatalogs()
	const catalogs = Array.isArray(collection)
		? collection
		: collection?.results || []
	return catalogs.find(
		(c) =>
			String(c.id) === String(id) || String(c?.['@self']?.id) === String(id),
	)
}

/**
 * The slug a catalog stub carries, else the slug of the local catalog with the
 * same id.
 *
 * @param {object|null} firstCatalog The first catalog stub.
 * @param {() => (Array<object>|{results: Array<object>}|null)} getCatalogs Returns the local catalog collection.
 * @return {string|null} The slug.
 */
function knownStubSlug(firstCatalog, getCatalogs) {
	if (firstCatalog?.slug) {
		return firstCatalog.slug
	}
	if (!firstCatalog?.id) {
		return null
	}
	const match = findCatalogById(firstCatalog.id, getCatalogs)
	return match?.slug || match?.['@self']?.slug || null
}

/**
 * The slug of the catalog stub in `@self.catalogs[0]`: its known slug, else
 * its title as a slug.
 *
 * @param {object|null} firstCatalog The first catalog stub.
 * @param {() => (Array<object>|{results: Array<object>}|null)} getCatalogs Returns the local catalog collection.
 * @return {string|null} The slug.
 */
function slugFromCatalogStub(firstCatalog, getCatalogs) {
	const slug = knownStubSlug(firstCatalog, getCatalogs)
	if (!slug && firstCatalog?.title) {
		return catalogSlugFromTitle(firstCatalog.title)
	}
	return slug
}

/**
 * The catalog slug of a result. The catalog arrives as a bare slug string, as a
 * catalog object, or only as `@self.catalogs[]` stubs (the fast-path
 * federation endpoint), which may lack a slug.
 *
 * @param {object|null} result The search result.
 * @param {() => (Array<object>|{results: Array<object>}|null)} getCatalogs Returns the local catalog collection; only called when a stub has an id and no slug.
 * @return {string|null} The slug.
 * @spec exclude presentation-only click-through
 */
export function resultCatalogSlug(result, getCatalogs) {
	const catalogSource = result?.['@self']?.catalog ?? result?.catalog
	if (typeof catalogSource === 'string') {
		return catalogSource
	}
	if (catalogSource?.slug) {
		return catalogSource.slug
	}
	const catalogsList = result?.['@self']?.catalogs
	const firstCatalog = Array.isArray(catalogsList) ? catalogsList[0] : null
	return slugFromCatalogStub(firstCatalog, getCatalogs)
}

/**
 * The origin of the peer that holds a federated result. `@self.uri` is the
 * peer's own object URL, the one origin the viewer can load; payloads without
 * a parseable uri fall back to `https://` plus the bare host of
 * `@self.directory` (a peer identifier).
 *
 * @param {object} result The search result.
 * @return {string|null} The origin, or null when no host can be derived.
 * @spec exclude presentation-only click-through
 */
export function peerOrigin(result) {
	const peerUri = result?.['@self']?.uri
	if (peerUri) {
		try {
			return new URL(peerUri).origin
		} catch {
			// Not a URL: derive the origin from the directory below.
		}
	}
	const bareHost = String(result?.['@self']?.directory)
		.replace(/^https?:\/\//i, '')
		.replace(/\/.*$/, '')
		.replace(/:$/, '')
	return bareHost ? `https://${bareHost}` : null
}

/**
 * The URL of a remote publication's own page on its source instance: the
 * source app's history-mode publication route when both the catalog slug and
 * the publication id are known, else the source app's root (a guessed slug
 * would 404 on a source with another default). Never a hash route.
 *
 * @param {object} publication The remote publication (its `@self.uri` or `@self.directory` names the source).
 * @param {string|null} catalogSlug The publication's catalog slug on the source, when known.
 * @return {string|null} The URL, or null when the source has no usable host.
 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-the-page-links-correctly-to-the-source-req-for-003
 */
export function sourceUrl(publication, catalogSlug) {
	const origin = peerOrigin(publication)
	if (!origin) {
		return null
	}
	const sourceRoot = `${origin}/index.php/apps/opencatalogi`
	const publicationId = publication?.id || publication?.['@self']?.id
	return catalogSlug && publicationId
		? `${sourceRoot}/publications/${encodeURIComponent(catalogSlug)}/${encodeURIComponent(publicationId)}`
		: `${sourceRoot}/`
}

/**
 * The in-app route to a federated result: FederatedPublication by id, with the
 * catalog slug as `catalog` when it is known so the page can link to the
 * source's own page.
 *
 * @param {{catalogSlug: string|null, publicationId: (string|number|undefined)}} link The resolved slug and id.
 * @return {{route: object}|null} The route to push, or nothing without an id.
 */
function federatedTarget(link) {
	if (!link.publicationId) {
		return null
	}
	const route = {
		name: 'FederatedPublication',
		params: { id: String(link.publicationId) },
	}
	if (link.catalogSlug) {
		route.query = { catalog: String(link.catalogSlug) }
	}
	return { route }
}

/**
 * The in-app route to a local result, when both its catalog slug and its
 * publication id are known; the slug-based detail route cannot resolve
 * anything less.
 *
 * @param {{catalogSlug: string|null, publicationId: (string|number|undefined)}} link The resolved slug and id.
 * @return {{route: object}|null} The route to push, or nothing.
 */
function localTarget(link) {
	if (!link.catalogSlug || !link.publicationId) {
		return null
	}
	return {
		route: {
			name: 'PublicationDetail',
			params: {
				catalogSlug: String(link.catalogSlug),
				id: String(link.publicationId),
			},
		},
	}
}

/**
 * What a click on a search result does. Local results carry
 * `@self.directory === 'local'`; any other non-empty directory is a federated
 * peer. Both stay inside the app: no new tab opens.
 *
 * @param {object|null} result The search result.
 * @param {() => (Array<object>|{results: Array<object>}|null)} getCatalogs Returns the local catalog collection.
 * @return {{route: object}|null} A route to push, or nothing.
 * @spec openspec/changes/federation-open-remote-publication/specs/federation/spec.md#requirement-a-federated-result-opens-inside-the-app-req-for-001
 */
export function resultClickTarget(result, getCatalogs) {
	const rawDirectory = result?.['@self']?.directory
	const link = {
		catalogSlug: resultCatalogSlug(result, getCatalogs),
		publicationId: result?.id || result?.['@self']?.id,
	}
	if (!rawDirectory || rawDirectory === 'local') {
		return localTarget(link)
	}
	return federatedTarget(link)
}
