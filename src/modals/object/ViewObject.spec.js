/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the "Schema" dropdown in `ViewObject.vue`, the
 * create-publication dialog: the options are listed alphanumerically on their
 * label, whatever order the store holds.
 */

import { mount } from '@vue/test-utils'
import ViewObject from './ViewObject.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))

jest.mock('../../store/store.js', () => {
	const catalog = {
		id: 'catalog-1',
		title: 'Catalog',
		registers: ['1'],
		schemas: ['10', '11', '12', '13'],
	}
	return {
		catalogStore: { isLoading: false, fetchPublications: jest.fn() },
		navigationStore: {
			modal: 'viewObject',
			getTransferData: () => null,
			setDialog: jest.fn(),
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
			selectedAttachments: [],
			getActiveObject: () => null,
			getCollection: (type) => ({
				results: type === 'catalog' ? [catalog] : [],
			}),
			getPagination: () => ({}),
			getRelatedData: () => null,
			isLoading: () => false,
			fetchCollection: jest.fn(),
			fetchRelatedData: jest.fn(),
			setActiveObject: jest.fn(),
			setSelectedObjects: jest.fn(),
		},
	}
})

function findSelect(wrapper, inputLabel) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((select) => select.props('inputLabel') === inputLabel)
}

describe('ViewObject schema dropdown', () => {
	beforeEach(() => {
		global.fetch = jest.fn((url) =>
			Promise.resolve({
				json: () => Promise.resolve(url.includes('/tags') ? [] : {}),
			}),
		)
	})

	afterEach(() => {
		delete global.fetch
	})

	it('lists the schemas for a new publication sorted on label', async () => {
		const wrapper = mount(ViewObject, {
			global: {
				mocks: { t: (app, text) => text },
				config: {
					globalProperties: { $route: { params: {} } },
				},
				stubs: {
					PaginationComponent: true,
					PropertiesPanel: true,
					PublishedIcon: true,
					AppTab: true,
					AppTabs: true,
				},
			},
		})
		// initializeData auto-selects the only catalog, then the only register.
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		const schemaSelect = findSelect(wrapper, 'Schema')
		const labels = schemaSelect.findAll('li').map((li) => li.text())

		expect(labels).toEqual(['Alpha', 'beta', 'Schema 2', 'Schema 10'])
		// No custom filter, so NcSelect's built-in typing filter applies.
		expect(schemaSelect.vm.$attrs).not.toHaveProperty('filterBy')
	})
})
