/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The report pages read the register the app ships (REP-001): every widget on
 * Reports, PublicationsReport, UsageReport and FederationReport that names a
 * register names the one in lib/Settings/publication_register.json, and every
 * schema it names is a schema of that register, so a default install answers
 * each widget instead of an unknown register.
 *
 * @spec openspec/changes/capability-rows-close-gaps/tasks.md#3.2
 */

import shipped from '../../lib/Settings/publication_register.json'
import manifest from '../manifest.json'

const REPORT_PAGES = ['Reports', 'PublicationsReport', 'UsageReport', 'FederationReport']

/**
 * Every object in a tree that carries a `register` key.
 *
 * @param {*} node The tree.
 * @param {Array<object>} found The sources found so far.
 * @return {Array<object>} The sources.
 */
function sources(node, found = []) {
	if (Array.isArray(node)) {
		node.forEach((child) => sources(child, found))
	} else if (node && typeof node === 'object') {
		if ('register' in node) {
			found.push(node)
		}
		Object.values(node).forEach((child) => sources(child, found))
	}
	return found
}

const registerSlugs = Object.values(shipped.components.registers).map((r) => r.slug)
const schemaSlugs = Object.keys(shipped.components.schemas)

describe('report pages read the shipped register (REP-001)', () => {
	it('has all four report pages', () => {
		const ids = manifest.pages.map((p) => p.id)
		REPORT_PAGES.forEach((id) => expect(ids).toContain(id))
	})

	it.each(REPORT_PAGES.filter((id) => id !== 'Reports'))('%s names only the shipped register', (id) => {
		const found = sources(manifest.pages.find((p) => p.id === id))
		expect(found.length).toBeGreaterThan(0)
		found.forEach((source) => {
			expect(registerSlugs).toContain(source.register)
			expect(source.register).not.toBe('opencatalogi')
		})
	})

	it.each(REPORT_PAGES.filter((id) => id !== 'Reports'))('%s names only schemas of that register', (id) => {
		sources(manifest.pages.find((p) => p.id === id))
			.filter((source) => source.schema !== undefined)
			.forEach((source) => expect(schemaSlugs).toContain(source.schema))
	})

	it('the Reports hub links to the three report pages', () => {
		const hub = JSON.stringify(manifest.pages.find((p) => p.id === 'Reports'))
		;['PublicationsReport', 'UsageReport', 'FederationReport'].forEach((id) => expect(hub).toContain(id))
	})
})
