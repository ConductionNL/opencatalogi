/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * publicationQuality: a publication's metadata-quality score as its detail page
 * shows it, from `GET /api/catalogs/{slug}/publications/{id}/quality` (PQM-001).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The DCAT property each missing entry names, as the officer knows the field.
 *
 * @param {(app: string, text: string) => string} t - the translate function.
 * @return {object} label by property.
 */
function fieldLabels(t) {
	return {
		'dct:title': t('opencatalogi', 'Title'),
		'dcat:keyword': t('opencatalogi', 'Keywords'),
		'dcat:theme': t('opencatalogi', 'Theme'),
		'dct:license': t('opencatalogi', 'License'),
		'dct:publisher': t('opencatalogi', 'Publisher'),
		'dct:identifier': t('opencatalogi', 'Identifier'),
		'dct:issued': t('opencatalogi', 'Publication date'),
		'dct:modified': t('opencatalogi', 'Last modified'),
		'dcat:landingPage': t('opencatalogi', 'Landing page'),
		'dcat:distribution': t('opencatalogi', 'Attachments'),
		'dct:format': t('opencatalogi', 'File format'),
		'dcat:mediaType': t('opencatalogi', 'File format'),
		'dcat:downloadURL': t('opencatalogi', 'Download link'),
		'dcat:accessURL': t('opencatalogi', 'Access link'),
	}
}

/**
 * The missing fields by name, each once, in the order the score names them.
 *
 * @param {Array<string>} missing - the DCAT properties the score names.
 * @param {(app: string, text: string) => string} t - the translate function.
 * @return {Array<string>}
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */
export function missingFieldNames(missing, t) {
	const labels = fieldLabels(t)
	const names = (Array.isArray(missing) ? missing : []).map((property) => labels[property] || property)
	return [...new Set(names)]
}

/**
 * Fetch a publication's score in a catalogue.
 *
 * @param {string} catalogSlug - the catalogue the publication is opened in.
 * @param {string} id - the publication.
 * @return {Promise<{total: number, missing: Array<string>}>}
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#task-2.5
 */
export async function fetchPublicationQuality(catalogSlug, id) {
	const url = generateUrl(`/apps/opencatalogi/api/catalogs/${encodeURIComponent(catalogSlug)}/publications/${encodeURIComponent(id)}/quality`)
	const response = await axios.get(url)
	return {
		total: Number.isFinite(response.data?.total) ? response.data.total : 0,
		missing: Array.isArray(response.data?.missing) ? response.data.missing : [],
	}
}
