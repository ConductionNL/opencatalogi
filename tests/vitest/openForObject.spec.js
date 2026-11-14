/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for openForObject: a row action activates its object, then opens
 * the target's modal, or its dialog with or without properties.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	navigationStore: { setModal: vi.fn(), setDialog: vi.fn() },
	objectStore: { setActiveObject: vi.fn() },
}))

vi.mock('../../src/store/store.js', () => stores)

const { openForObject } = await import('../../src/services/openForObject.js')

describe('openForObject', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('activates the object and opens a modal target, and no dialog', () => {
		const item = { id: 1 }
		openForObject(item, { type: 'publication', modal: 'viewObject', dialog: 'ignored' })

		expect(stores.objectStore.setActiveObject).toHaveBeenCalledWith('publication', item)
		expect(stores.navigationStore.setModal).toHaveBeenCalledWith('viewObject')
		expect(stores.navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('opens a dialog with its properties', () => {
		openForObject({ id: 2 }, { type: 'catalog', dialog: 'deleteObject', properties: { mode: 'hard' } })

		expect(stores.objectStore.setActiveObject).toHaveBeenCalledWith('catalog', { id: 2 })
		expect(stores.navigationStore.setDialog).toHaveBeenCalledWith('deleteObject', { mode: 'hard' })
		expect(stores.navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('opens a dialog without properties with the name alone', () => {
		openForObject({ id: 3 }, { type: 'catalog', dialog: 'copyObject' })

		expect(stores.navigationStore.setDialog).toHaveBeenCalledTimes(1)
		expect(stores.navigationStore.setDialog.mock.calls[0]).toEqual(['copyObject'])
	})
})
