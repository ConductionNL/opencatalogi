/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Shared config for the compact dashboard list widgets (catalogs,
 * unpublished publications, unpublished attachments, most-viewed
 * publications). Each widget shapes its rows to `{ id, mainText, subText }`
 * and renders the universal `<CnDataTable>` headerless with these columns —
 * a bold name and a muted, right-aligned trailing summary, matching the
 * compact list look shared across the Conduction apps (ADR-049).
 */

/**
 * Columns for a headerless name + trailing-summary list. `mainText` and
 * `subText` are the keys produced by each widget's `items` computed; the
 * `cn-cell--*` utilities live in @conduction/nextcloud-vue's table.css.
 *
 * @type {Array<{key: string, cellClass: string}>}
 */
export const LIST_COLUMNS = [
	{ key: 'mainText', cellClass: 'cn-cell--strong' },
	{ key: 'subText', cellClass: 'cn-cell--muted cn-cell--end' },
]

/**
 * Same-tab navigation used by row clicks. A plain `window.location.href`
 * resolves correctly both inside the in-app router and when the widget runs
 * standalone on the Nextcloud Dashboard (where no vue-router is present).
 *
 * @param {string} url The (generateUrl-resolved) target URL.
 * @return {void}
 */
export function navigateTo(url) {
	if (url) {
		window.location.href = url
	}
}
