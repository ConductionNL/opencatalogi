/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for two helpers of `ViewObject.vue`: the register options of the
 * chosen catalog, and the border removal on the rich-text editor.
 */

import ViewObject from './ViewObject.vue'
import { objectStore } from '../../store/store.js'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: { getCollection: jest.fn(), availableRegisters: [] },
	catalogStore: {},
}))

describe('ViewObject registerOptions', () => {
	/**
	 * The register options for `selectedCatalog`.
	 *
	 * @param {object|null} selectedCatalog The chosen catalog.
	 * @return {Array<object>} The options.
	 */
	function options(selectedCatalog) {
		return ViewObject.computed.registerOptions.call({ selectedCatalog })
	}

	beforeEach(() => {
		objectStore.getCollection.mockReturnValue({
			results: [
				{ id: 1, registers: ['10', 12] },
				{ id: 2 },
			],
		})
		objectStore.availableRegisters = [
			{ id: 10, title: 'Ten' },
			{ id: 11, title: 'Eleven' },
			{ id: 12, title: 'Twelve' },
		]
	})

	it('offers nothing without a catalog', () => {
		expect(options(null)).toEqual([])
	})

	it('offers nothing for an unknown catalog', () => {
		expect(options({ id: 9 })).toEqual([])
	})

	it('offers the catalog registers, matched as text', () => {
		expect(options({ id: '1' })).toEqual([
			{ id: 10, label: 'Ten' },
			{ id: 12, label: 'Twelve' },
		])
		expect(objectStore.getCollection).toHaveBeenCalledWith('catalog')
	})

	it('offers nothing for a catalog without registers', () => {
		expect(options({ id: 2 })).toEqual([])
	})
})

describe('ViewObject removeBordersFromEditor', () => {
	const BORDERLESS = {
		border: 'none',
		borderWidth: '0',
		borderStyle: 'none',
		borderColor: 'transparent',
		outline: 'none',
		boxShadow: 'none',
	}

	it('clears the borders of the editor and everything in it', () => {
		const children = [{ style: {} }, { style: {} }]
		const container = { style: {}, querySelectorAll: jest.fn(() => children) }
		ViewObject.methods.removeBordersFromEditor({ getEl: () => container })

		expect(container.querySelectorAll).toHaveBeenCalledWith('*')
		for (const el of [container, ...children]) {
			expect(el.style).toEqual(BORDERLESS)
		}
	})

	it('does nothing without an editor element', () => {
		expect(() => ViewObject.methods.removeBordersFromEditor({ getEl: () => null })).not.toThrow()
	})

	it('warns instead of throwing when the editor fails', () => {
		const warn = jest.spyOn(console, 'warn').mockImplementation(() => {})
		const error = new Error('gone')
		ViewObject.methods.removeBordersFromEditor({
			getEl: () => {
				throw error
			},
		})

		expect(warn).toHaveBeenCalledWith('Could not remove borders from editor:', error)
		warn.mockRestore()
	})
})
