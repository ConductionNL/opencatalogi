/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the "Schemas" dropdown in `CatalogModal.vue`: the options
 * are listed alphanumerically on their label, whatever order the store holds.
 */

import { mount } from '@vue/test-utils'
import CatalogModal from './CatalogModal.vue'

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'catalog', setModal: jest.fn() },
	objectStore: {
		availableRegisters: [
			{ id: 1, title: 'Register A', schemas: [{ id: 10 }, { id: 11 }] },
			{ id: 2, title: 'Register B', schemas: [{ id: 12 }, { id: 13 }] },
		],
		availableSchemas: [
			{ id: 12, title: 'Schema 10', registerTitle: 'Register B' },
			{ id: 10, title: 'beta', registerTitle: 'Register A' },
			{ id: 13, title: 'Schema 2', registerTitle: 'Register B' },
			{ id: 11, title: 'Alpha', registerTitle: 'Register A' },
		],
		getActiveObject: () => null,
		getCollection: () => ({ results: [] }),
		getState: () => ({ success: null }),
		isLoading: () => false,
	},
}))

function mountModal() {
	return mount(CatalogModal, {
		global: {
			mocks: { t: (app, text) => text },
		},
	})
}

function findSelect(wrapper, inputLabel) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((select) => select.props('inputLabel') === inputLabel)
}

async function choose(wrapper, inputLabel, value) {
	findSelect(wrapper, inputLabel).vm.$emit('update:modelValue', value)
	await wrapper.vm.$nextTick()
}

function optionLabels(wrapper, inputLabel) {
	return findSelect(wrapper, inputLabel)
		.findAll('li')
		.map((li) => li.text())
}

const bothRegisters = [
	{ id: 1, label: 'Register A' },
	{ id: 2, label: 'Register B' },
]

describe('CatalogModal schema dropdown', () => {
	it('lists the schemas of the selected registers sorted on label', async () => {
		const wrapper = mountModal()

		await choose(wrapper, 'Registers*', bothRegisters)

		expect(optionLabels(wrapper, 'Schemas*')).toEqual([
			'Alpha (Register A)',
			'beta (Register A)',
			'Schema 2 (Register B)',
			'Schema 10 (Register B)',
		])
	})

	it('keeps the sort order when a selected schema drops out of the list', async () => {
		const wrapper = mountModal()

		await choose(wrapper, 'Registers*', bothRegisters)
		await choose(wrapper, 'Schemas*', [{ id: 10, label: 'beta (Register A)' }])

		expect(optionLabels(wrapper, 'Schemas*')).toEqual([
			'Alpha (Register A)',
			'Schema 2 (Register B)',
			'Schema 10 (Register B)',
		])
	})
})
