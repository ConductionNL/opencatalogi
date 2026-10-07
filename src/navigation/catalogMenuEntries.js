// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Manifest `order` of the per-catalog menu entries: after Dashboard (10) and
 * before Search (30). Entries sharing one order keep their relative order,
 * which is the order the server returned the catalogs in.
 */
export const CATALOG_MENU_ORDER = 20

export const CATALOG_MENU_ICON = 'DatabaseEyeOutline'

export const CATALOG_MENU_ROUTE = 'Publications'

/**
 * Build one menu entry per catalog, opening that catalog's publications.
 *
 * Catalogs without a slug are skipped, since the publications route needs
 * one, and a slug seen before is skipped so every entry id stays unique. The
 * label is the catalog's title, which is user data, so it is shown as typed
 * rather than translated.
 *
 * @param {Array<{slug?: string, title?: string}>} catalogs Catalogs in server order.
 * @return {Array<object>} Manifest menu entries.
 */
export function catalogMenuEntries(catalogs) {
	const seen = new Set()
	const entries = []
	for (const catalog of Array.isArray(catalogs) ? catalogs : []) {
		const slug = catalog?.slug ? String(catalog.slug) : ''
		if (slug === '' || seen.has(slug)) {
			continue
		}
		seen.add(slug)
		const title = typeof catalog.title === 'string' ? catalog.title : ''
		entries.push({
			id: `catalog-${slug}`,
			label: title.trim() !== '' ? title : slug,
			translateLabel: false,
			icon: CATALOG_MENU_ICON,
			route: CATALOG_MENU_ROUTE,
			params: { catalogSlug: slug },
			order: CATALOG_MENU_ORDER,
		})
	}
	return entries
}

/**
 * The manifest with one menu entry per catalog appended to its `menu`.
 *
 * Never mutates the input. Returns the same manifest reference when there is
 * no entry to add, so the navigation does not re-render for nothing.
 *
 * @param {object} manifest The menu manifest.
 * @param {Array<{slug?: string, title?: string}>} catalogs Catalogs in server order.
 * @return {object} The manifest to render.
 */
export function withCatalogEntries(manifest, catalogs) {
	const entries = catalogMenuEntries(catalogs)
	if (entries.length === 0) {
		return manifest
	}
	return {
		...manifest,
		menu: [...(manifest?.menu ?? []), ...entries],
	}
}
