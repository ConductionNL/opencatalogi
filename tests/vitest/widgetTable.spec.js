/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the shared compact list widget config: the headerless
 * columns, same-tab row navigation and the options every list widget mixes in.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	LIST_COLUMNS,
	LIST_WIDGET_OPTIONS,
	navigateTo,
} from '../../src/views/widgets/widgetTable.js'

describe('LIST_COLUMNS', () => {
	it('shows a bold main text and a muted, right-aligned summary', () => {
		expect(LIST_COLUMNS).toEqual([
			{ key: 'mainText', cellClass: 'cn-cell--strong' },
			{ key: 'subText', cellClass: 'cn-cell--muted cn-cell--end' },
		])
	})
})

describe('navigateTo', () => {
	let original

	beforeEach(() => {
		original = window.location
		window.location = { href: '/start' }
	})

	afterEach(() => {
		window.location = original
	})

	it('sends the browser to the given url', () => {
		navigateTo('/apps/opencatalogi/catalogi/7')
		expect(window.location.href).toBe('/apps/opencatalogi/catalogi/7')
	})

	it.each([[''], [null], [undefined]])('stays put for %j', (url) => {
		expect(navigateTo(url)).toBeUndefined()
		expect(window.location.href).toBe('/start')
	})
})

describe('LIST_WIDGET_OPTIONS', () => {
	it('requires a string title', () => {
		expect(LIST_WIDGET_OPTIONS.props).toEqual({
			title: { type: String, required: true },
		})
	})

	it('starts not loading, with the shared columns, fresh per instance', () => {
		const first = LIST_WIDGET_OPTIONS.data()
		expect(first).toEqual({ loading: false, columns: LIST_COLUMNS })
		expect(first.columns).toBe(LIST_COLUMNS)
		expect(LIST_WIDGET_OPTIONS.data()).not.toBe(first)
	})

	it('fetches the widget data once on mount', () => {
		const ctx = { fetchData: vi.fn() }
		LIST_WIDGET_OPTIONS.mounted.call(ctx)
		expect(ctx.fetchData).toHaveBeenCalledTimes(1)
		expect(ctx.fetchData).toHaveBeenCalledWith()
	})
})
