/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the Nextcloud Dashboard bundle entry points: each registers
 * its widget id with OCA.Dashboard and, when the dashboard calls back, mounts
 * its widget component with the dashboard's title and the l10n helpers.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const apps = []

vi.mock('vue', () => ({
	createApp: vi.fn((component, props) => {
		const app = { component, props, mixin: vi.fn(), mount: vi.fn() }
		apps.push(app)
		return app
	}),
}))

vi.mock('../../src/views/widgets/CatalogiWidget.vue', () => ({ default: { name: 'CatalogiWidget' } }))
vi.mock('../../src/views/widgets/MostViewedPublicationsWidget.vue', () => ({ default: { name: 'MostViewedPublicationsWidget' } }))
vi.mock('../../src/views/widgets/RetentionWidget.vue', () => ({ default: { name: 'RetentionWidget' } }))
vi.mock('../../src/views/widgets/UnpublishedAttachmentsWidget.vue', () => ({ default: { name: 'UnpublishedAttachmentsWidget' } }))
vi.mock('../../src/views/widgets/UnpublishedPublicationsWidget.vue', () => ({ default: { name: 'UnpublishedPublicationsWidget' } }))

const ENTRIES = [
	['catalogiWidget.js', 'opencatalogi_catalogi_widget', 'CatalogiWidget'],
	['mostViewedPublicationsWidget.js', 'opencatalogi_most_viewed_publications_widget', 'MostViewedPublicationsWidget'],
	['retentionWidget.js', 'opencatalogi_retention_widget', 'RetentionWidget'],
	['unpublishedAttachmentsWidget.js', 'opencatalogi_unpublished_attachments_widget', 'UnpublishedAttachmentsWidget'],
	['unpublishedPublicationsWidget.js', 'opencatalogi_unpublished_publications_widget', 'UnpublishedPublicationsWidget'],
]

const importers = {
	'catalogiWidget.js': () => import('../../src/catalogiWidget.js'),
	'mostViewedPublicationsWidget.js': () => import('../../src/mostViewedPublicationsWidget.js'),
	'retentionWidget.js': () => import('../../src/retentionWidget.js'),
	'unpublishedAttachmentsWidget.js': () => import('../../src/unpublishedAttachmentsWidget.js'),
	'unpublishedPublicationsWidget.js': () => import('../../src/unpublishedPublicationsWidget.js'),
}

let register

beforeEach(() => {
	vi.resetModules()
	apps.length = 0
	register = vi.fn()
	globalThis.OCA = { Dashboard: { register } }
})

afterEach(() => {
	delete globalThis.OCA
})

describe.each(ENTRIES)('%s', (file, widgetId, componentName) => {
	it('registers its widget id with the dashboard once, without mounting', async () => {
		await importers[file]()

		expect(register).toHaveBeenCalledTimes(1)
		expect(register.mock.calls[0][0]).toBe(widgetId)
		expect(register.mock.calls[0][1]).toBeTypeOf('function')
		expect(apps).toHaveLength(0)
	})

	it('mounts its widget into the given element with the dashboard title', async () => {
		await importers[file]()
		const callback = register.mock.calls[0][1]
		const el = { id: 'widget-host' }

		await callback(el, { widget: { title: 'My widget title' } })

		expect(apps).toHaveLength(1)
		const [app] = apps
		expect(app.component.name).toBe(componentName)
		expect(app.props).toEqual({ title: 'My widget title' })
		expect(app.mixin).toHaveBeenCalledTimes(1)
		const { methods } = app.mixin.mock.calls[0][0]
		expect(Object.keys(methods).sort()).toEqual(['n', 't'])
		expect(methods.t('opencatalogi', 'Hi {who}', { who: 'Ann' })).toBe('Hi Ann')
		expect(methods.n('opencatalogi', '{count} item', '{count} items', 2)).toBe('2 items')
		expect(app.mount).toHaveBeenCalledExactlyOnceWith(el)
	})
})
