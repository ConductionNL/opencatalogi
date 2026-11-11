/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The metadata rows the catalog detail page shows.
 */
import { translate as t } from '@nextcloud/l10n'
import { staffAccessLine } from './catalogStaffAccess.js'

/**
 * A value, or a dash when it is empty.
 *
 * @param {unknown} value The value.
 * @return {unknown} The value, or '-'.
 */
function orDash(value) {
	return value || '-'
}

/**
 * A timestamp in the viewer's locale, or a dash when there is none.
 *
 * @param {string|undefined} value The timestamp.
 * @return {string} The formatted timestamp, or '-'.
 */
function localeDateOrDash(value) {
	return value ? new Date(value).toLocaleString() : '-'
}

/**
 * The rows that describe a catalog: title, summary, slug, status and listing.
 *
 * @param {object} catalog The catalog.
 * @return {Array<{label: string, value: unknown}>} The rows.
 */
function descriptionRows(catalog) {
	return [
		{ label: t('opencatalogi', 'Title'), value: orDash(catalog.title) },
		{ label: t('opencatalogi', 'Summary'), value: orDash(catalog.summary) },
		{ label: t('opencatalogi', 'Slug'), value: orDash(catalog.slug) },
		{ label: t('opencatalogi', 'Status'), value: orDash(catalog.status) },
		{
			label: t('opencatalogi', 'Listed'),
			value: catalog.listed
				? t('opencatalogi', 'Public')
				: t('opencatalogi', 'Private'),
		},
	]
}

/**
 * The rows about who may work in a catalog and its record: staff access,
 * created, updated and id.
 *
 * @param {object} catalog The catalog.
 * @return {Array<{label: string, value: unknown}>} The rows.
 */
function recordRows(catalog) {
	const self = catalog['@self'] || {}
	return [
		{
			label: t('opencatalogi', 'Staff access'),
			value: staffAccessLine(catalog.staffAccess),
		},
		{
			label: t('opencatalogi', 'Created'),
			value: localeDateOrDash(self.created),
		},
		{
			label: t('opencatalogi', 'Updated'),
			value: localeDateOrDash(self.updated),
		},
		{ label: t('opencatalogi', 'ID'), value: orDash(self.id || catalog.id) },
	]
}

/**
 * The metadata rows of a catalog: title, summary, slug, status, listing,
 * staff access, created, updated and id.
 *
 * @param {object|null} catalog The catalog.
 * @return {Array<{label: string, value: unknown}>} The rows, none without a catalog.
 * @spec openspec/changes/retrofit-2026-05-26-catalog-management/tasks.md#task-3
 */
export function catalogMetadataItems(catalog) {
	if (!catalog) return []
	return [...descriptionRows(catalog), ...recordRows(catalog)]
}

/**
 * "Yes" or "No" for a catalog feature flag.
 *
 * @param {unknown} flag The flag.
 * @return {string} The translated answer.
 */
function yesNo(flag) {
	return flag ? t('opencatalogi', 'Yes') : t('opencatalogi', 'No')
}

/**
 * The configuration rows counting a catalog's registers and schemas.
 *
 * @param {object} catalog The catalog.
 * @return {Array<{label: string, value: number}>} The rows.
 */
export function catalogCountRows(catalog) {
	return [
		{
			label: t('opencatalogi', 'Registers'),
			value: (catalog.registers || []).length,
		},
		{
			label: t('opencatalogi', 'Schemas'),
			value: (catalog.schemas || []).length,
		},
	]
}

/**
 * The configuration rows saying which publication features a catalog has.
 *
 * @param {object} catalog The catalog.
 * @return {Array<{label: string, value: string}>} The rows.
 */
export function catalogFeatureRows(catalog) {
	return [
		{
			label: t('opencatalogi', 'WOO Sitemap'),
			value: yesNo(catalog.hasWooSitemap),
		},
		{
			label: t('opencatalogi', 'OOAPI 5.0 Publication'),
			value: yesNo(catalog.hasOoapi),
		},
	]
}
