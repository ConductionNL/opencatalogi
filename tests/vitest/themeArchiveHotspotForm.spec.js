/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The theme page lets an administrator mark a subject as an archive hotspot
 * (theme-archive-hotspot, REQ-THA-001): the Theme Data widget shows the
 * toggle, the reason and the marking moment to readers in the admin group
 * only, the moment never editable, and the create and edit forms never ask
 * for them, so an editor outside the admin group is not offered a field
 * ThemeHotspotGuard refuses.
 *
 * @spec openspec/changes/theme-archive-hotspot/tasks.md#task-1.4
 */

import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))
const HOTSPOT = ['archiveHotspot', 'archiveHotspotReason', 'archiveHotspotSince']

/**
 * The manifest page with the given id.
 *
 * @param {object} node A manifest node.
 * @param {string} id The page id.
 * @return {object|null} The page.
 */
function page(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = page(child, id)
			if (found) {
				return found
			}
		}
	} else if (node && typeof node === 'object') {
		if (node.id === id && node.config) {
			return node
		}
		for (const child of Object.values(node)) {
			const found = page(child, id)
			if (found) {
				return found
			}
		}
	}
	return null
}

describe('the theme page marks an archive hotspot for administrators only', () => {
	const manifest = readJson(path.join(ROOT, 'src/manifest.json'))
	const detail = page(manifest, 'ThemeDetail')
	const index = page(manifest, 'Themes')
	const data = detail.config.widgets.find((widget) => widget.id === 'theme-data')
	const fragment = readJson(path.join(ROOT, 'lib/Settings/register.d/theme-archive-hotspot.json'))
	const properties = fragment.components.schemas.theme.properties

	it('names properties the theme schema declares', () => {
		HOTSPOT.forEach((key) => expect(properties[key], key).toBeDefined())
	})

	it('shows the toggle, the reason and the moment only to the admin group', () => {
		HOTSPOT.forEach((key) => {
			expect(data.content.overrides[key].visibleWhen, key).toEqual({ readerGroups: ['admin'] })
		})
	})

	it('keeps the toggle and the reason editable and the marking moment not', () => {
		expect(data.content.overrides.archiveHotspot.editable).not.toBe(false)
		expect(data.content.overrides.archiveHotspotReason.editable).not.toBe(false)
		expect(data.content.overrides.archiveHotspotSince.editable).toBe(false)
	})

	it('leaves the fields out of the create and edit forms', () => {
		expect(detail.config.excludeFields).toEqual(expect.arrayContaining(HOTSPOT))
		expect(index.config.excludeFields).toEqual(expect.arrayContaining(HOTSPOT))
	})

	it('hides the fields from a reader whose groups are not yet known or not admin', () => {
		// No signed-in user in the test runner: the groups read as unknown, so hidden.
		expect(evaluateVisibleWhenLocal(data.content.overrides.archiveHotspot.visibleWhen, {})).toBe(false)
	})
})
