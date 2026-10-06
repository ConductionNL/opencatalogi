/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the "Schema" dropdown in `ObjectModal.vue`: the options
 * are listed alphanumerically on their label, whatever order the store holds.
 */

import { mount } from '@vue/test-utils'
import ObjectModal from './ObjectModal.vue'

jest.mock('@codemirror/lang-json', () => ({
	json: () => ({}),
	jsonParseLinter: () => () => [],
}))
jest.mock('vue-codemirror6', () => ({ name: 'CodeMirror', render: () => null }))

jest.mock('../../store/store.js', () => {
	const active = {}
	const catalog = {
		id: 'catalog-1',
		title: 'Catalog',
		registers: [1],
		schemas: [10, 11, 12, 13],
	}
	return {
		catalogStore: {},
		navigationStore: {
			modal: 'objectModal',
			getTransferData: () => 'create',
			setModal: jest.fn(),
		},
		objectStore: {
			availableRegisters: [
				{
					id: 1,
					title: 'Register',
					schemas: [{ id: 10 }, { id: 11 }, { id: 12 }, { id: 13 }],
				},
			],
			availableSchemas: [
				{ id: 12, title: 'Schema 10' },
				{ id: 10, title: 'beta' },
				{ id: 13, title: 'Schema 2' },
				{ id: 11, title: 'Alpha' },
			],
			getActiveObject: (type) => active[type] ?? null,
			setActiveObject: (type, object) => {
				active[type] = object
			},
			getCollection: () => ({ results: [catalog] }),
			isLoading: () => false,
		},
	}
})

function findSelect(wrapper, label) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((select) => select.props('ariaLabelCombobox') === label)
}

describe('ObjectModal schema dropdown', () => {
	it('lists the schemas of the selected catalog and register sorted on label', async () => {
		const wrapper = mount(ObjectModal, {
			global: {
				mocks: { t: (app, text) => text },
			},
		})
		await wrapper.vm.$nextTick()

		const labels = findSelect(wrapper, 'Schema')
			.findAll('li')
			.map((li) => li.text())

		expect(labels).toEqual(['Alpha', 'beta', 'Schema 2', 'Schema 10'])
	})
})
