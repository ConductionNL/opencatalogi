/**
 * Extract a publication type id (trailing path segment) from a URL.
 *
 * @param {string} url - The publication-type URL
 * @return {string} The trailing path segment
 * @spec openspec/changes/retrofit-2026-05-25-content-management/tasks.md#task-5
 */
export const getPublicationTypeId = (url) => {
	const publicationTypeId = url.substring(url.lastIndexOf('/') + 1, url.length)
	return publicationTypeId
}
