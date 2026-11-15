/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the object store's initial state: every key,
 * its initial value (the default metadata columns included) and that each
 * store gets its own copies. Run with RECORD_GOLDEN=1 to rewrite the fixture.
 */

import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => {
	const plugin = () => ({})
	return {
		createObjectStore: () => () => ({}),
		auditTrailsPlugin: plugin,
		filesPlugin: plugin,
		lifecyclePlugin: plugin,
		liveUpdatesPlugin: plugin,
		relationsPlugin: plugin,
		selectionPlugin: plugin,
	}
})

import { useObjectStore } from '../../src/store/modules/object.js'

const FIXTURE = path.resolve(__dirname, 'fixtures/object-store-state-golden.json')

/**
 * A fresh store's initial state as plain data.
 *
 * @return {object} The state
 */
function initialState() {
	setActivePinia(createPinia())
	return JSON.parse(JSON.stringify(useObjectStore().$state))
}

describe('object store initial state (golden)', () => {
	it('matches the recorded state', () => {
		const state = initialState()
		if (process.env.RECORD_GOLDEN === '1') {
			fs.writeFileSync(FIXTURE, JSON.stringify(state, null, '\t') + '\n')
		}
		const golden = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
		expect(state).toEqual(golden)
		expect(Object.keys(state)).toEqual(Object.keys(golden))
		expect(Object.keys(state.metadata)).toEqual(Object.keys(golden.metadata))
	})

	it('gives every store its own metadata columns', () => {
		setActivePinia(createPinia())
		const first = useObjectStore()
		first.metadata.name.enabled = false
		first.metadata.extra = { enabled: true }
		setActivePinia(createPinia())
		const second = useObjectStore()
		expect(second.metadata.name.enabled).toBe(true)
		expect(second.metadata.extra).toBeUndefined()
		expect(second.metadata.name).not.toBe(first.metadata.name)
	})
})
