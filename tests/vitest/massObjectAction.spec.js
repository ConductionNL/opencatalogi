/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/modals/object/massObjectAction.js: how the Mass*Objects
 * dialogs record an outcome and close.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: { fetchPublications: vi.fn() },
	navigationStore: { setDialog: vi.fn() },
	objectStore: { selectedObjects: [{ id: 1 }, { id: 2 }] },
}))

vi.mock('../../src/store/store.js', () => stores)

import massObjectAction, {
	AUTO_CLOSE_MS,
} from '../../src/modals/object/massObjectAction.js'

/**
 * A component instance carrying the mixin's state and methods.
 *
 * @return {object} The instance.
 */
function dialog() {
	const vm = { ...massObjectAction.data() }
	for (const [name, fn] of Object.entries(massObjectAction.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

const failedText = (count) => `Failed ${count}`

describe('massObjectAction', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.clearAllMocks()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('starts open with no outcome', () => {
		const vm = dialog()
		expect(vm.success).toBeNull()
		expect(vm.error).toBe(false)
		expect(vm.loading).toBe(false)
	})

	it('remembers how many objects were selected when mounted', () => {
		const vm = dialog()
		massObjectAction.mounted.call(vm)
		expect(vm.originalSelectedCount).toBe(2)
	})

	it('closes itself after a run without failures', () => {
		const vm = dialog()
		vm.finishMassAction({ successful: [1, 2], failed: [] }, failedText)

		expect(vm.success).toBe(true)
		expect(vm.error).toBe(false)
		expect(stores.catalogStore.fetchPublications).toHaveBeenCalledOnce()
		expect(stores.navigationStore.setDialog).not.toHaveBeenCalled()

		vi.advanceTimersByTime(AUTO_CLOSE_MS)
		expect(stores.navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('stays open and reports failures next to partial success', () => {
		const vm = dialog()
		vm.finishMassAction({ successful: [1], failed: [2, 3] }, failedText)

		expect(vm.success).toBe(true)
		expect(vm.error).toBe('Failed 2')
		expect(stores.catalogStore.fetchPublications).toHaveBeenCalledOnce()
		vi.runAllTimers()
		expect(stores.navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('reports a run where everything failed without refreshing', () => {
		const vm = dialog()
		vm.finishMassAction({ successful: [], failed: [1] }, failedText)

		expect(vm.success).toBeNull()
		expect(vm.error).toBe('Failed 1')
		expect(stores.catalogStore.fetchPublications).not.toHaveBeenCalled()
	})

	it('does nothing when no object was processed', () => {
		const vm = dialog()
		vm.finishMassAction({ successful: [], failed: [] }, failedText)

		expect(vm.success).toBeNull()
		expect(vm.error).toBe(false)
		vi.runAllTimers()
		expect(stores.navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('cancels a pending auto-close when closed by hand', () => {
		const vm = dialog()
		vm.finishMassAction({ successful: [1], failed: [] }, failedText)
		vm.closeDialog()

		expect(vm.closeModalTimeout).toBeNull()
		vi.runAllTimers()
		expect(stores.navigationStore.setDialog).toHaveBeenCalledOnce()
	})
})
