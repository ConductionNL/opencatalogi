/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { dispatchObjectsChanged } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

/**
 * The steps the upload dialog shares between a first upload and a retry:
 * reading an upload answer, counting settled uploads, the publication's
 * files URL and change notice, and the schema's auto-share configuration.
 */

/**
 * Note the id of the attachment an upload created, on the file and in the
 * id map. An answer without a created id changes nothing.
 *
 * @param {object} file - The uploaded file
 * @param {{response: object, idMap: object}} upload - The upload answer and the id map
 * @return {void}
 */
export function recordCreatedId(file, { response, idMap }) {
	try {
		const payload = Array.isArray(response?.data) ? response.data : []
		const created = payload && payload[0]
		if (created && created.id) {
			file.id = created.id
			idMap[file.name] = created.id
		}
	} catch {
		// An answer that cannot be read creates no id.
	}
}

/**
 * How many settled uploads ended with the given status.
 *
 * @param {Array<{status: string}>} results - The settled uploads
 * @param {string} status - 'fulfilled' or 'rejected'
 * @return {number} The count
 */
export function countSettled(results, status) {
	return results.filter((r) => r.status === status).length
}

/**
 * The URL of a publication's files.
 *
 * @param {{registerId: string, schemaId: string}} ids - The register and schema ids
 * @param {object} publication - The publication
 * @return {string} The URL
 */
export function publicationFilesUrl({ registerId, schemaId }, publication) {
	return generateUrl(
		'/apps/openregister/api/objects/{registerId}/{schemaId}/{objectId}/files',
		{ registerId, schemaId, objectId: publication.id },
	)
}

/**
 * Tell the lists that a publication's files changed.
 *
 * @param {{registerId: string, schemaId: string}} ids - The register and schema ids
 * @param {object} publication - The publication
 * @return {void}
 */
export function notifyPublicationChanged({ registerId, schemaId }, publication) {
	dispatchObjectsChanged({
		register: registerId,
		schema: schemaId,
		id: publication.id,
	})
}

/**
 * The configuration an embedded schema object carries.
 *
 * @param {object|string|number} schemaRef - The schema reference
 * @return {object|null} The configuration
 */
export function embeddedSchemaConfiguration(schemaRef) {
	return typeof schemaRef === 'object' && schemaRef !== null
		? schemaRef.configuration
		: null
}

/**
 * The id a schema is fetched by: an embedded object's id, uuid or slug, or
 * the reference itself.
 *
 * @param {object|string|number} schemaRef - The schema reference
 * @return {string|number|undefined} The id
 */
function schemaFetchId(schemaRef) {
	return typeof schemaRef === 'object'
		? schemaRef.id || schemaRef.uuid || schemaRef.slug
		: schemaRef
}

/**
 * Fetch the configuration of a referenced schema; null when it has no id,
 * cannot be fetched or read, or carries none.
 *
 * @param {object|string|number} schemaRef - The schema reference
 * @return {Promise<object|null>} The configuration
 */
export async function fetchSchemaConfiguration(schemaRef) {
	const schemaId = schemaFetchId(schemaRef)
	if (!schemaId) return null
	const response = await fetch(
		`/index.php/apps/openregister/api/schemas/${schemaId}`,
	)
	if (!response.ok) return null
	const schema = await response.json().catch(() => null)
	return schema?.configuration || null
}
