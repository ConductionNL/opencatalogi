/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * The page is declared in JSON and resolves two built-in formatters, one
 * handler and one icon by NAME. A misspelled name renders a raw enum, no
 * glyph, or an Add integration that does nothing, and none of them logs a
 * thing. So this spec
 * reads the real fragment and checks every name against what has to answer it.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-oc-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import * as connectionRegistry from '../../src/services/connectionRegistry.js'

// The built-ins import @nextcloud/l10n, whose auth dependency reads `window`
// at load. The node environment has none, and an untranslated call returns
// the English source string, which is what the checks compare.
globalThis.window ??= globalThis
const { BUILT_IN_FORMATTERS } =
	await import('@conduction/nextcloud-vue/dist/esm/utils/builtInFormatters.js')

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const fragment = JSON.parse(read('src', 'manifest.d', 'connection-registry.json'))
const page = fragment.pages.find((p) => p.id === 'Integrations')
const menu = fragment.menu.find((m) => m.id === 'IntegrationsMenu')

/**
 * The formatter registry the page renders with, built the way CnAppRoot builds
 * it: `{ ...BUILT_IN_FORMATTERS, ...props.formatters }`. A local copy passed to
 * CnAppRoot under a built-in's name wins, so a copy that predates a status
 * shows that status as its raw word.
 *
 * @return {Object<string, Function>} Formatter name to formatter.
 */
function pageFormatters() {
	const local = /createConnectionFormatters\(/.test(read('src', 'App.vue'))
		? connectionRegistry.createConnectionFormatters((app, source) => source)
		: {}
	return { ...BUILT_IN_FORMATTERS, ...local }
}

describe('connection formatters', () => {
	it('reads a switched-off connection as Switched off, from the built-in', () => {
		expect(pageFormatters().connectionStatus('disabled')).toBe('Switched off')
		expect(read('src', 'App.vue')).not.toContain(':formatters=')
	})

	it('ships an English and a Dutch catalogue entry for every label the page shows', () => {
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const labels = [
			page.title,
			menu.label,
			page.config.folderSidebar.allLabel,
			...page.config.headerActions.map((a) => a.label),
			...page.config.columns.map((c) => c.label),
		]
		for (const label of labels) {
			expect(en[label], `en: ${label}`).toBe(label)
			expect(nl[label], `nl: ${label}`).toBeTruthy()
		}
	})
})

describe('Add integration handler', () => {
	it('opens integriq on the link dialog, preset to opencatalogi', () => {
		const opened = []
		const handlers = connectionRegistry.createConnectionHandlers({
			generateUrl: (p) => `/index.php${p}`,
			assign: (url) => opened.push(url),
		})

		handlers.openIntegriqConnections()

		expect(connectionRegistry.INTEGRIQ_CONNECTIONS_PATH).toBe(
			'/apps/integriq/connections?app=opencatalogi&link=1',
		)
		expect(opened).toEqual([
			'/index.php/apps/integriq/connections?app=opencatalogi&link=1',
		])
	})
})

describe('the Integrations page declaration', () => {
	it('lists integriq app_connection rows, admin only, and requires integriq', () => {
		expect(page.type).toBe('index')
		expect(page.route).toBe('/settings/integrations')
		expect(page.permission).toBe('admin')
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
		expect(page.config.defaultSort).toEqual({ field: 'order', direction: 'asc' })
	})

	// A row nothing declared has nothing to check (connection-registry D9).
	it('offers no generic Add button', () => {
		expect(page.config.showAdd).toBe(false)
	})

	// THE PRESET. integriq's schema holds every app's rows. Without the query
	// the page lists them all as though they were this app's.
	it('scopes the rows to opencatalogi through the menu preset, in the gear', () => {
		expect(menu.route).toBe(page.id)
		expect(menu.query).toEqual({ app: 'opencatalogi' })
		expect(menu.section).toBe('settings')
		expect(menu.permission).toBe('admin')
		expect(menu.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('names only formatters and handlers that exist, and wires both into the app', () => {
		const formatters = pageFormatters()
		const handlers = connectionRegistry.createConnectionHandlers({
			generateUrl: (p) => p,
			assign: () => {},
		})

		for (const column of page.config.columns.filter((c) => c.formatter)) {
			expect(typeof formatters[column.formatter], column.formatter).toBe(
				'function',
			)
		}
		for (const action of page.config.headerActions) {
			expect(typeof handlers[action.handler], action.handler).toBe('function')
		}

		expect(read('src', 'registry.js')).toMatch(
			/^\t\.\.\.createConnectionHandlers\(\{$/m,
		)
	})

	it('names an icon src/icons.js registers', () => {
		const icons = read('src', 'icons.js')
		for (const icon of [
			menu.icon,
			...page.config.headerActions.map((a) => a.icon),
		]) {
			expect(icons).toContain(`\n\t${icon},`)
		}
	})

	it('keeps its id and route apart from every page the base manifest declares', () => {
		const base = JSON.parse(read('src', 'manifest.json'))
		expect(base.pages.map((p) => p.id)).not.toContain(page.id)
		expect(base.pages.map((p) => p.route)).not.toContain(page.route)
		expect(base.menu.map((m) => m.id)).not.toContain(menu.id)
	})

	// menu-layout.json runs after the fragments merge. A removal or relocation
	// naming this entry would hide it without a sound.
	it('is not removed or moved by the menu layout', () => {
		const layout = JSON.parse(read('src', 'menu-layout.json'))
		expect(layout.removals).not.toContain(menu.id)
		expect(Object.keys(layout.relocations)).not.toContain(menu.id)
	})
})
