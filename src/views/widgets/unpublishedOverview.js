/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The unpublished overview the two dashboard widgets read: one call to
 * `GET /api/dashboard/unpublished` (`kind=publications` or `kind=documents`),
 * which already applies the reader's access rules, and the rows as the
 * compact list widget shows them.
 *
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read one list of the unpublished overview.
 *
 * @param {'publications'|'documents'} kind The list.
 * @param {object} [http] An axios-like client.
 * @return {Promise<Array<object>>} The items; an empty list when it could not be read.
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
 */
export async function fetchUnpublished(kind, http = axios) {
	try {
		const { data } = await http.get(generateUrl('/apps/opencatalogi/api/dashboard/unpublished'), { params: { kind } })
		return Array.isArray(data?.items) ? data.items : []
	} catch {
		return []
	}
}

/**
 * The detail page of an item's publication, or null when its catalogue is unknown.
 *
 * @param {{publicationId?: string, catalogSlug?: string|null}} item An overview item.
 * @return {string|null}
 * @spec openspec/changes/publications-unpublished-overview/specs/dashboard/spec.md#requirement-unpublished-content-dashboard-widgets-dsh-011
 */
export function publicationUrl(item) {
	if (!item?.catalogSlug || !item?.publicationId) {
		return null
	}
	return generateUrl(`/apps/opencatalogi/publications/${item.catalogSlug}/${item.publicationId}`)
}

/**
 * A publications-list item as a widget row.
 *
 * @param {object} item An overview item.
 * @return {object}
 */
export function publicationRow(item) {
	return { id: item.publicationId, mainText: item.title, subText: item.publicationDate || '', item }
}

/**
 * A documents-list item as a widget row: the file, under its publication's title.
 *
 * @param {object} item An overview item.
 * @return {object}
 */
export function documentRow(item) {
	return { id: `${item.publicationId}-${item.fileId}`, mainText: item.title, subText: item.publicationTitle || '', item }
}
