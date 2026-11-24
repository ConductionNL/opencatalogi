/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings page registers every component its template uses in
 * `components: {}`, so nothing depends on a global registration
 * (admin-settings 11.6).
 *
 * @spec openspec/changes/admin-settings/tasks.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(resolve(__dirname, '../../src/views/settings/Settings.vue'), 'utf8')

/**
 * The PascalCase tags in the template.
 *
 * @param {string} sfc The single-file component.
 * @return {string[]} The tag names, sorted and unique.
 */
function templateTags(sfc) {
	const template = sfc.slice(sfc.indexOf('<template>'), sfc.lastIndexOf('</template>'))
	return [...new Set([...template.matchAll(/<([A-Z][A-Za-z0-9]+)/g)].map((m) => m[1]))].sort()
}

/**
 * The names in the `components: {}` option.
 *
 * @param {string} sfc The single-file component.
 * @return {Set<string>} The registered names.
 */
function registered(sfc) {
	const block = sfc.match(/components:\s*\{([^}]*)\}/)
	return new Set(block ? [...block[1].matchAll(/([A-Z][A-Za-z0-9]+)/g)].map((m) => m[1]) : [])
}

describe('admin settings page', () => {
	it('registers every component its template uses', () => {
		const tags = templateTags(source)
		const names = registered(source)

		expect(tags.length).toBeGreaterThan(0)
		expect(tags.filter((tag) => !names.has(tag))).toEqual([])
	})

	it('reads an unregistered component as missing', () => {
		const sfc = '<template><NcButton /><Missing /></template><script>export default { components: { NcButton } }</script>'

		expect(templateTags(sfc).filter((tag) => !registered(sfc).has(tag))).toEqual(['Missing'])
	})
})
