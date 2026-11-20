/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Organizations pages read and write OpenRegister's shared organisation
 * (organisations-page-edits-the-shared-organisation, REQ-SHO-103): both pages
 * resolve the organisation keys instead of the retired `organization` schema in
 * the listing register, delete stays off, and the detail page's identity block
 * shows the government identifiers in two columns.
 *
 * @spec openspec/changes/organisations-page-edits-the-shared-organisation/specs/shared-organisation/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(
	readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
)
const page = (id) => manifest.pages.find((candidate) => candidate.id === id)

describe('the Organizations pages on the shared organisation', () => {
	it.each(['Organizations', 'OrganizationDetail'])(
		'%s resolves the organisation register and schema',
		(id) => {
			const { config } = page(id)
			expect(config.register).toBe('@resolve:organization_register')
			expect(config.schema).toBe('@resolve:organization_schema')
			expect(JSON.stringify(config)).not.toMatch(/"schema":"organization"/)
			expect(config.register).not.toBe('@resolve:listing_register')
		},
	)

	it('lets the index add an organisation but never delete one, as OpenRegister refuses that and points at merging', () => {
		const toggles = page('Organizations').config.actionToggles
		expect(toggles.showAdd).toBe(true)
		expect(toggles.showDeleteAction).toBe(false)
		expect(toggles.showMassDelete).toBe(false)
	})

	it('shows the name, summary and the government identifiers in two columns on the detail page', () => {
		const data = page('OrganizationDetail').config.widgets.find(
			(widget) => widget.id === 'org-data',
		)
		expect(data.type).toBe('data')
		expect(data.content.columns).toBe(2)
		expect(data.content.include).toEqual([
			'name',
			'summary',
			'oin',
			'tooi',
			'rsin',
			'pki',
		])
	})

	it('keeps the publications and catalogs lists filtering on the organisation', () => {
		const widgets = page('OrganizationDetail').config.widgets
		for (const id of ['org-publications', 'org-catalogs']) {
			expect(
				widgets.find((widget) => widget.id === id).content.filter,
			).toEqual({ organization: '@objectId' })
		}
	})
})
