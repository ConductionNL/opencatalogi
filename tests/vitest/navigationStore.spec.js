/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the navigation (`ui`) Pinia store
 * (src/store/modules/navigation.ts): the menu, catalogue, modal and dialog
 * selections, and the read-once transfer data hand-off.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useNavigationStore } from '../../src/store/modules/navigation.ts'

describe('navigation store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'log').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('starts on the dashboard with nothing open', () => {
		const store = useNavigationStore()
		expect(store.selected).toBe('dashboard')
		expect(store.selectedCatalogus).toBeNull()
		expect(store.modal).toBeNull()
		expect(store.dialog).toBeNull()
		expect(store.dialogProperties).toBeNull()
		expect(store.transferData).toBeNull()
	})

	it('setSelected, setSelectedCatalogus and setModal store the value and log it', () => {
		const store = useNavigationStore()
		store.setSelected('publications')
		store.setSelectedCatalogus('cat-1')
		store.setModal('editPublication')

		expect(store.selected).toBe('publications')
		expect(store.selectedCatalogus).toBe('cat-1')
		expect(store.modal).toBe('editPublication')
		expect(console.log).toHaveBeenCalledWith('Active menu item set to publications')
		expect(console.log).toHaveBeenCalledWith('Active catalogus menu set to cat-1')
		expect(console.log).toHaveBeenCalledWith('Active modal set to editPublication')
	})

	it('setDialog keeps its properties and says so', () => {
		const store = useNavigationStore()
		const properties = { title: 'Delete', id: 7 }
		store.setDialog('deleteObject', properties)

		expect(store.dialog).toBe('deleteObject')
		expect(store.dialogProperties).toEqual({ title: 'Delete', id: 7 })
		expect(console.log).toHaveBeenCalledWith('Active dialog set to deleteObject', 'with properties')
	})

	it('setDialog without properties clears earlier ones', () => {
		const store = useNavigationStore()
		store.setDialog('deleteObject', { id: 1 })
		store.setDialog(null)

		expect(store.dialog).toBeNull()
		expect(store.dialogProperties).toBeNull()
		expect(console.log).toHaveBeenLastCalledWith('Active dialog set to null', '')
	})

	it('getTransferData returns the data once and then clears it', () => {
		const store = useNavigationStore()
		store.setTransferData('payload')

		expect(store.transferData).toBe('payload')
		expect(store.getTransferData()).toBe('payload')
		expect(store.transferData).toBeNull()
		expect(store.getTransferData()).toBeNull()
	})
})
