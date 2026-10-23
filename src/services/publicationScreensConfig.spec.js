/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public inspection card and the Publication policy page are manifest
 * configuration; these checks pin the sources they read.
 */

import policy from '../manifest.d/publication-policy-page.json'
import manifest from '../manifest.json'

describe('public inspection card', () => {
	it('lists the comment periods of the page publication', () => {
		const page = manifest.pages.find((p) => p.id === 'PublicationDetail')
		const card = page.config.widgets.find((w) => w.id === 'pub-inspection')

		expect(card.type).toBe('record-list')
		expect(card.content.schema).toBe('commentPeriod')
		expect(card.content.filter).toEqual({ publication: '@objectId' })
		expect(page.config.layout.map((l) => l.widgetId)).toContain('pub-inspection')
	})
})

describe('Publication policy page', () => {
	const page = policy.pages[0]
	const widget = (id) => page.config.widgets.find((w) => w.id === id)

	it('is an admin page in the settings section', () => {
		expect(page.permission).toBe('admin')
		expect(policy.menu[0]).toMatchObject({
			route: page.id,
			section: 'settings',
			permission: 'admin',
		})
	})

	it('reads the rules, the collections and the banner', () => {
		expect(widget('policy-rules').content.schema).toBe('publicationRule')
		expect(widget('policy-banner').content.schema).toBe('instanceBanner')
		expect(widget('policy-collections').content.endpointSource).toEqual({
			url: '/apps/opencatalogi/api/published-collections',
			responsePath: 'collections',
		})
	})
})
