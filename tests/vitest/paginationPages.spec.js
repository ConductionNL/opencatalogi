/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/services/paginationPages.js: the page buttons of the
 * pagination bar. The fixture holds what PaginationComponent's visiblePages
 * computed returned for a grid of current pages and page counts. Offline,
 * no DOM.
 */
import { describe, expect, it } from 'vitest'
import { visiblePageItems } from '../../src/services/paginationPages.js'
import golden from './fixtures/pagination-visible-pages-golden.json'

describe('visiblePageItems (golden)', () => {
	it.each(golden.map((c) => [c.current, c.total, c]))(
		'page %s of %s',
		(_current, _total, testCase) => {
			expect(visiblePageItems(testCase.current, testCase.total)).toEqual(
				testCase.pages,
			)
		},
	)
})
