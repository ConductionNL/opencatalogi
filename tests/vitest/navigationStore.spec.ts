/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tests for the navigation store: the active menu item, catalogue, modal and
 * dialog it holds, and the transfer data it hands over exactly once.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useNavigationStore } from '../../src/store/modules/navigation.ts'

describe('navigation store', () => {
	let store: ReturnType<typeof useNavigationStore>
	let logs: unknown[][]

	beforeEach(() => {
		setActivePinia(createPinia())
		logs = []
		vi.spyOn(console, 'log').mockImplementation((...args: unknown[]) => {
			logs.push(args)
		})
		store = useNavigationStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('starts on the dashboard with nothing open', () => {
		expect(store.$state).toEqual({
			selected: 'dashboard',
			selectedCatalogus: null,
			modal: null,
			dialog: null,
			dialogProperties: null,
			transferData: null,
		})
	})

	it('sets the active menu item, catalogue and modal and logs each', () => {
		store.setSelected('publication')
		store.setSelectedCatalogus('cat-1')
		store.setModal('editPublication')

		expect(store.selected).toBe('publication')
		expect(store.selectedCatalogus).toBe('cat-1')
		expect(store.modal).toBe('editPublication')
		expect(logs).toEqual([
			['Active menu item set to publication'],
			['Active catalogus menu set to cat-1'],
			['Active modal set to editPublication'],
		])
	})

	it('opens a dialog with its properties', () => {
		const properties = { objectType: 'publication', operation: 'publish' as const, isMultiple: true }
		store.setDialog('massPublish', properties)

		expect(store.dialog).toBe('massPublish')
		expect(store.dialogProperties).toEqual(properties)
		expect(logs).toEqual([['Active dialog set to massPublish', 'with properties']])
	})

	it('drops earlier dialog properties when a dialog opens without them', () => {
		store.setDialog('massPublish', { objectType: 'publication' })
		store.setDialog('deleteObject')

		expect(store.dialog).toBe('deleteObject')
		expect(store.dialogProperties).toBeNull()
		expect(logs[1]).toEqual(['Active dialog set to deleteObject', ''])
	})

	it('hands transfer data over once and then clears it', () => {
		store.setTransferData('payload')

		expect(store.transferData).toBe('payload')
		expect(store.getTransferData()).toBe('payload')
		expect(store.transferData).toBeNull()
		expect(store.getTransferData()).toBeNull()
	})
})
