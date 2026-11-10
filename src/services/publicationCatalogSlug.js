/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * An id as text, or the empty string when there is none.
 *
 * @param {*} value - The id
 * @return {string} The text
 */
function idText(value) {
	return value?.toString() || ''
}

/**
 * The catalog slug of a publication's detail link: the slug of the listed
 * catalog with the publication's catalog id, else the slug of an embedded
 * catalog object.
 *
 * @param {object} publication - The publication
 * @param {Array<object|null>} catalogs - The listed catalogs
 * @return {string|undefined} The slug
 * @spec exclude link plumbing; no domain behavior of its own.
 */
export function listedCatalogSlug(publication, catalogs) {
	const catalogId = publication?.catalog?.id || publication?.catalog
	const matchedCatalog = catalogs.find(
		(c) => idText(c?.id) === idText(catalogId),
	)
	return matchedCatalog?.slug || publication?.catalog?.slug
}
