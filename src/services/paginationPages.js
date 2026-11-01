/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page buttons a pagination bar shows.
 */

/**
 * The whole numbers from `from` up to and including `to`, stepping by one.
 *
 * @param {number} from The first number.
 * @param {number} to The last number.
 * @return {Array<number>} The numbers.
 */
function pageRange(from, to) {
	const pages = []
	for (let i = from; i <= to; i++) {
		pages.push(i)
	}
	return pages
}

/**
 * The page numbers and ellipses of a pagination bar: every page up to seven
 * pages; otherwise the first and last page, a window around the current page
 * and `'...'` for each gap.
 *
 * @param {number} current The current page.
 * @param {number} total The number of pages.
 * @return {Array<number|string>} The page numbers and ellipses.
 * @spec openspec/changes/retrofit-2026-05-26-object-table-listing/tasks.md#task-2
 */
export function visiblePageItems(current, total) {
	if (total <= 7) {
		return pageRange(1, total)
	}
	if (current <= 4) {
		return [1, ...pageRange(2, 5), '...', total]
	}
	if (current >= total - 3) {
		return [1, '...', ...pageRange(total - 4, total)]
	}
	return [1, '...', ...pageRange(current - 1, current + 1), '...', total]
}
