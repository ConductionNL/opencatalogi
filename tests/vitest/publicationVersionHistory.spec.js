/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The publication page lists the publication's versions from OpenRegister's
 * content versioning and compares two of them field by field, through the
 * library's `version-history` integration (capability-rows-close-gaps 2.4).
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'PublicationDetail')

/**
 * Whether two layout cells share any grid square.
 *
 * @param {object} a A layout cell.
 * @param {object} b Another layout cell.
 * @return {boolean} True when they overlap.
 */
function overlaps(a, b) {
	return a.gridX < b.gridX + b.gridWidth
		&& b.gridX < a.gridX + a.gridWidth
		&& a.gridY < b.gridY + b.gridHeight
		&& b.gridY < a.gridY + a.gridHeight
}

describe('publication version history', () => {
	it('declares the version-history integration on the publication page', () => {
		const widget = page.config.widgets.find((w) => w.id === 'pub-versions')

		expect(widget).toEqual({
			id: 'pub-versions',
			type: 'integration',
			integrationId: 'version-history',
			title: 'Version history',
			icon: 'FileCompare',
		})
	})

	it('reads the publication itself, not another object', () => {
		const widget = page.config.widgets.find((w) => w.id === 'pub-versions')

		// Without props the host hands the page's register, schema and object id.
		expect(widget.props).toBeUndefined()
		expect(page.config.register).toBeTruthy()
		expect(page.config.schema).toBeTruthy()
	})

	it('places the card full width without covering another widget', () => {
		const layout = page.config.layout
		const cell = layout.find((l) => l.widgetId === 'pub-versions')

		expect(cell).toMatchObject({ gridX: 0, gridWidth: 12 })
		expect(layout.filter((l) => l.id === cell.id)).toHaveLength(1)
		for (const other of layout.filter((l) => l !== cell)) {
			expect(overlaps(cell, other), `${other.widgetId} overlaps the version history`).toBe(false)
		}
	})

	it('keeps the raw event log in the sidebar History tab', () => {
		const tab = page.config.sidebar.tabs.find((t) => t.id === 'audit')

		expect(tab.widgets).toEqual([{ type: 'audit' }])
	})
})
