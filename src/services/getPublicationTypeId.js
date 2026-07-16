/**
 * Extract a publication type id (trailing path segment) from a URL.
 *
 * @param {string} url - The publication-type URL
 * @return {string} The trailing path segment
 * @spec openspec/specs/content-management/spec.md
 */
export const getPublicationTypeId = (url) => {
	const publicationTypeId = url.substring(url.lastIndexOf('/') + 1, url.length)
	return publicationTypeId
}
