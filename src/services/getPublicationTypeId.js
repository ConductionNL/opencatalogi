/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Extract a publication type id (trailing path segment) from a URL.
 *
 * @param {string} url - The publication-type URL
 * @return {string} The trailing path segment
 * @spec openspec/specs/content-management/spec.md
 */
export function getPublicationTypeId(url) {
	const publicationTypeId = url.substring(url.lastIndexOf('/') + 1, url.length)
	return publicationTypeId
}
