/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The icon registry must name every `icon` the app's manifests use: an
 * unregistered name renders no icon at all in the navigation.
 */

import { readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import icons from '../../src/icons.js'

const src = resolve(__dirname, '../../src')

const manifests = [
	join(src, 'manifest.json'),
	...readdirSync(join(src, 'manifest.d'))
		.filter((file) => file.endsWith('.json'))
		.map((file) => join(src, 'manifest.d', file)),
]

/**
 * Collect every string `icon` value anywhere in a parsed manifest.
 *
 * @param {*} node Parsed JSON node.
 * @param {Set<string>} found Accumulator.
 * @return {Set<string>} The accumulator.
 */
function collectIcons(node, found) {
	if (Array.isArray(node)) {
		node.forEach((child) => collectIcons(child, found))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (key === 'icon' && typeof value === 'string') {
				found.add(value)
			} else {
				collectIcons(value, found)
			}
		}
	}
	return found
}

const used = manifests.reduce(
	(found, file) => collectIcons(JSON.parse(readFileSync(file, 'utf8')), found),
	new Set(),
)

describe('icon registry', () => {
	it('finds icons in the manifests', () => {
		expect(used.size).toBeGreaterThan(0)
		expect(used.has('ViewDashboardOutline')).toBe(true)
	})

	it.each([...used].sort())('registers the manifest icon %s', (name) => {
		expect(Object.hasOwn(icons, name)).toBe(true)
		expect(icons[name]).toBeTruthy()
	})

	it('maps PascalCase names to imported icon components', () => {
		for (const [name, component] of Object.entries(icons)) {
			expect(name).toMatch(/^[A-Z][A-Za-z0-9]*$/)
			expect(component).toEqual({ name: 'IconStub' })
		}
	})
})
