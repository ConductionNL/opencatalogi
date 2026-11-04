/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the branchy manifest and menu helpers:
 * resolveManifestSentinelsSync, catalogMenuEntries, translateActionLabels and
 * the selection-dialog handlers of createPublicationActionHandlers.
 * Run with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ values: {}, reads: [] }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => {
		state.reads.push(app + ':' + key)
		if (key === 'throws_register') {
			throw new Error('no state')
		}
		return key in state.values ? state.values[key] : fallback
	},
}))

const { resolveManifestSentinelsSync } =
	await import('../../src/services/manifestSentinels.js')
const { catalogMenuEntries } =
	await import('../../src/navigation/catalogMenuEntries.js')
const { translateActionLabels } =
	await import('../../src/services/translateActionLabels.js')
const { createPublicationActionHandlers } =
	await import('../../src/services/publicationActions.js')

const FIXTURE = path.resolve(
	__dirname,
	'fixtures/menu-and-manifest-branches-golden.json',
)
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded =
	RECORD || !fs.existsSync(FIXTURE)
		? {}
		: JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
const actual = {}

/**
 * Compare one case with the recording, or record it.
 *
 * @param {string} name The case name
 * @param {object} value The answer, JSON-safe
 */
function check(name, value) {
	actual[name] = JSON.parse(
		JSON.stringify(value === undefined ? '(undefined)' : value),
	)
	if (!RECORD) {
		expect(actual[name]).toEqual(recorded[name])
	}
}

afterAll(() => {
	if (RECORD) {
		fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
	}
})

describe('resolveManifestSentinelsSync (golden)', () => {
	it('substitutes set values, keeps unset ones and reads each key once', () => {
		state.values = {
			catalog_register: '12',
			catalog_schema: 7,
			empty_schema: '',
			null_schema: null,
			zero_schema: 0,
			false_schema: false,
		}
		state.reads = []
		const manifest = {
			version: '1',
			pages: [
				{
					id: 'a',
					config: {
						register: '@resolve:catalog_register',
						schema: '@resolve:catalog_schema',
					},
				},
				{
					id: 'b',
					config: {
						list: [
							'@resolve:catalog_register',
							3,
							null,
							['@resolve:empty_schema'],
						],
						nested: {
							deep: {
								value: '@resolve:catalog_register',
								other: '@resolve:missing_key',
							},
						},
						bad: '@resolve:Upper',
						prefixed: 'x@resolve:catalog_register',
						flags: [true, false, 0],
						zero: '@resolve:zero_schema',
						no: '@resolve:false_schema',
						nil: '@resolve:null_schema',
						thrown: '@resolve:throws_register',
						again: '@resolve:throws_register',
					},
				},
				{ id: 'c' },
				{ id: 'd', config: 'string config' },
				{ id: 'e', config: null },
				null,
				'page-as-string',
				{ id: 'f', config: [] },
				{ id: 'g', config: '@resolve:catalog_register' },
			],
		}
		const out = resolveManifestSentinelsSync(manifest)
		check('sentinels: resolved', {
			out,
			reads: state.reads,
			samePageC: out.pages[2] === manifest.pages[2],
		})
	})

	it('answers a manifest without pages', () => {
		check('sentinels: no pages', resolveManifestSentinelsSync({ version: '2' }))
		check(
			'sentinels: pages not a list',
			resolveManifestSentinelsSync({ pages: 'x' }),
		)
	})
})

describe('catalogMenuEntries (golden)', () => {
	it.each([
		['not a list', 'x'],
		['empty', []],
		[
			'mixed',
			[
				null,
				{},
				{ slug: '' },
				{ slug: 'woo', title: 'Woo' },
				{ slug: 'woo', title: 'Duplicate' },
				{ slug: 7, title: '   ' },
				{ slug: 'no-title' },
				{ slug: 'num-title', title: 42 },
				{ slug: 0, title: 'zero slug' },
			],
		],
	])('%s', (name, catalogs) => {
		check('menu: ' + name, catalogMenuEntries(catalogs))
	})
})

describe('translateActionLabels (golden)', () => {
	it('translates labels in actions, bulk actions and the pair config', () => {
		const tr = (text) => 'T(' + text + ')'
		const manifest = {
			pages: [
				null,
				{ id: 'no-config' },
				{ id: 'no-actions', config: { a: 1 } },
				{
					id: 'actions',
					config: {
						actions: [
							{ label: 'Edit' },
							'builtin:edit',
							null,
							{ label: 3 },
							{ id: 'x' },
						],
						bulkActions: 'not a list',
					},
				},
				{
					id: 'pair',
					config: {
						bulkActions: [{ label: 'Publish' }],
						publicationPairConfig: {
							actions: [{ label: 'Pair' }],
							bulkActions: [],
						},
					},
				},
				{
					id: 'pair-without-actions',
					config: { publicationPairConfig: { other: true } },
				},
			],
		}
		const out = translateActionLabels(manifest, tr)
		check('labels: manifest', {
			out,
			unchanged: out.pages.map(
				(page, index) => page === manifest.pages[index],
			),
		})
		check('labels: no pages', translateActionLabels({ a: 1 }, tr))
		check('labels: undefined', translateActionLabels(undefined, tr))
	})
})

describe('openSelectionDialog (golden)', () => {
	it.each([
		['modal', { objects: [{ id: 1 }], props: { modal: 'm' } }],
		['dialog', { objects: [{ id: 1 }, { id: 2 }], props: { dialog: 'd' } }],
		['both', { objects: [{ id: 1 }], props: { modal: 'm', dialog: 'd' } }],
		['no target', { objects: [{ id: 1 }], props: {} }],
		['no objects', { objects: [], props: { modal: 'm' } }],
		['objects missing', { props: { modal: 'm' } }],
		['props missing', { objects: [{ id: 1 }] }],
		['props null', { objects: [{ id: 1 }], props: null }],
		['objects without length', { objects: {}, props: { dialog: 'd' } }],
	])('%s', (name, payload) => {
		const calls = []
		const handlers = createPublicationActionHandlers({
			objectStore: {
				setSelectedObjects: (objects) => calls.push(['selected', objects]),
			},
			navigationStore: {
				setModal: (modal) => calls.push(['modal', modal]),
				setDialog: (dialog) => calls.push(['dialog', dialog]),
			},
		})
		let answer
		try {
			answer = handlers.openSelectionDialog(payload)
		} catch (error) {
			answer = 'threw ' + error.constructor.name
		}
		check('selection: ' + name, { answer, calls })
	})
})
