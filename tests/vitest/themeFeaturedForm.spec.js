/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The theme editor offers the featured toggle and the order (REQ-SUB-001):
 * the order follows the toggle, on the Theme card and in the create and edit
 * forms. The theme page lists the publications filed under the subject with
 * their state, newest first (REQ-SUB-003).
 *
 * @spec openspec/changes/subjects-as-first-class-records/tasks.md#task-4.1
 */

import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'))

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

describe('the theme editor features a subject', () => {
	const manifest = readJson(path.join(ROOT, 'src/manifest.json'))
	const detail = page(manifest, 'ThemeDetail')
	const index = page(manifest, 'Themes')
	const data = detail.config.widgets.find((widget) => widget.id === 'theme-data')
	const list = detail.config.widgets.find((widget) => widget.id === 'theme-publications')
	const theme = readJson(path.join(ROOT, 'lib/Settings/publication_register.json')).components.schemas.theme.properties

	it('names properties the theme schema declares', () => {
		expect(theme.featured.type).toBe('boolean')
		expect(theme.featuredOrder.type).toBe('integer')
	})

	it('keeps the toggle and the order on the Theme card, the order shown only once featured', () => {
		expect(data.content.include ?? ['featured']).toContain('featured')
		expect(data.content.exclude ?? []).not.toContain('featured')
		const rule = data.content.overrides.featuredOrder.visibleWhen
		expect(evaluateVisibleWhenLocal(rule, { featured: true })).toBe(true)
		expect(evaluateVisibleWhenLocal(rule, { featured: false })).toBe(false)
	})

	it('asks for the order in the create and edit forms only once featured', () => {
		for (const config of [detail.config, index.config]) {
			expect(config.excludeFields ?? []).not.toContain('featured')
			expect(config.fieldOverrides.featuredOrder.condition).toEqual({ field: 'featured', truthy: true })
		}
	})

	it('lists the publications filed under the subject with their state, newest first', () => {
		expect(list.type).toBe('object-list')
		expect(list.content.filter).toEqual({ themes: '@objectId' })
		expect(list.content.columns.map((column) => column.key)).toContain('status')
		expect(list.content.sort).toEqual({ field: 'publicationDate', dir: 'desc' })
	})
})
