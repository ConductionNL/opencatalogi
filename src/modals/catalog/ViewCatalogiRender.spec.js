/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for what the catalogue view dialog `ViewCatalogi.vue` shows:
 * the catalogue's details, the registers, schemas and organisation tabs with
 * their empty states, and the actions in the footer.
 */

import { mount } from '@vue/test-utils'
import ViewCatalogi from './ViewCatalogi.vue'
import { navigationStore, objectStore } from '../../store/store.js'

jest.mock('../../store/store.js', () => ({
	navigationStore: { modal: 'viewCatalogi', setModal: jest.fn(), setDialog: jest.fn() },
	objectStore: {
		catalog: null,
		organization: null,
		getActiveObject() {
			return this.catalog
		},
		getObject() {
			return this.organization
		},
		setActiveObject: jest.fn(),
		availableRegisters: [
			{ id: 1, title: 'Publicaties', description: 'Het register van de publicaties' },
			{ id: 2, title: 'Woo' },
		],
		availableSchemas: [{ id: 10, title: 'Besluit', description: 'Een besluit van het college' }],
	},
}))

const stubs = {
	NcDialog: { props: ['name'], template: '<div class="dialog" :data-name="name"><slot /><div class="footer"><slot name="actions" /></div></div>' },
	NcEmptyContent: { props: ['name', 'description'], template: '<div class="empty">{{ name }}|{{ description }}<slot name="icon" /></div>' },
	NcActions: { template: '<div class="actions"><slot /></div>' },
	NcActionButton: { template: '<button class="action" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcButton: { template: '<button class="close" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
}

const router = { push: jest.fn() }

/**
 * Mount the dialog over the given catalogue and organisation.
 *
 * @param {object|null} catalog The active catalogue.
 * @param {object|null} organization What the organisation lookup answers.
 * @return {object} The wrapper.
 */
function mountDialog(catalog, organization = null) {
	objectStore.catalog = catalog
	objectStore.organization = organization
	return mount(ViewCatalogi, {
		global: { stubs, mixins: [{ methods: { t: (app, text) => text } }], config: { globalProperties: { $router: router } } },
	})
}

/**
 * Open the tab with the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The tab's label.
 * @return {Promise<void>}
 */
async function openTab(wrapper, label) {
	await wrapper.findAll('.tabHeader').find((tab) => tab.text() === label).trigger('click')
}

beforeEach(() => {
	jest.clearAllMocks()
	navigationStore.modal = 'viewCatalogi'
})

const catalog = {
	id: 'c1',
	slug: 'woo',
	title: 'Woo-catalogus',
	summary: 'Alles wat de gemeente actief openbaar maakt',
	description: 'De catalogus van de Woo-publicaties',
	listed: true,
	registers: [1, 3],
	schemas: [10, 11],
	organization: 'o1',
}

describe('ViewCatalogi details', () => {
	it('names the catalogue and shows its summary, status, description and counts', () => {
		const wrapper = mountDialog(catalog)

		expect(wrapper.find('.dialog').attributes('data-name')).toBe('View Catalog: Woo-catalogus')
		expect(wrapper.find('h2').text()).toBe('Woo-catalogus')
		expect(wrapper.find('.catalogSummary').text()).toBe('Alles wat de gemeente actief openbaar maakt')
		expect(wrapper.findAll('.propertyItem').map((item) => item.text())).toEqual([
			'Status:Publicly findable',
			'Description:De catalogus van de Woo-publicaties',
			'Registers:2',
			'Schemas:2',
		])
	})

	it('reads an unlisted catalogue without summary, description, registers or schemas', () => {
		const wrapper = mountDialog({ id: 'c2', title: 'Intern', listed: false })

		expect(wrapper.find('.catalogSummary').exists()).toBe(false)
		expect(wrapper.findAll('.propertyItem').map((item) => item.text())).toEqual([
			'Status:Not publicly findable',
			'Registers:0',
			'Schemas:0',
		])
	})

	it('names an unknown catalogue when none is active', () => {
		const wrapper = mountDialog(null)

		expect(wrapper.find('.dialog').attributes('data-name')).toBe('View Catalog: Unknown')
	})

	it('renders nothing while another modal is open', () => {
		navigationStore.modal = 'catalog'
		const wrapper = mountDialog(catalog)

		expect(wrapper.find('.dialog').exists()).toBe(false)
	})
})

describe('ViewCatalogi tabs', () => {
	it('lists the registers, an unknown one by name, with the descriptions it has', () => {
		const wrapper = mountDialog(catalog)

		expect(wrapper.findAll('.itemCard h3').map((h) => h.text())).toEqual(['Publicaties', 'Unknown Register'])
		expect(wrapper.findAll('.itemDescription').map((p) => p.text())).toEqual(['Het register van de publicaties'])
		expect(wrapper.find('.tabHeader.active').text()).toBe('Registers')
	})

	it('lists the schemas on the second tab', async () => {
		const wrapper = mountDialog(catalog)

		await openTab(wrapper, 'Schemas')

		expect(wrapper.find('.tabHeader.active').text()).toBe('Schemas')
		expect(wrapper.findAll('.itemCard h3').map((h) => h.text())).toEqual(['Besluit', 'Unknown Schema'])
		expect(wrapper.findAll('.itemDescription').map((p) => p.text())).toEqual(['Een besluit van het college'])
	})

	it('shows the empty state of each tab for a catalogue without them', async () => {
		const wrapper = mountDialog({ id: 'c2', title: 'Leeg', registers: [], schemas: [] })

		expect(wrapper.find('.empty').text()).toBe('No registers found|This catalog has no associated registers.')
		await openTab(wrapper, 'Schemas')
		expect(wrapper.find('.empty').text()).toBe('No schemas found|This catalog has no associated schemas.')
		await openTab(wrapper, 'Organization')
		expect(wrapper.find('.empty').text()).toBe('No organization|This catalog has no associated organization.')
	})

	it('shows the organisation and opens it on the organisations page', async () => {
		const organization = { id: 'o1', name: 'Gemeente Zuiddrecht', description: 'De gemeente' }
		const wrapper = mountDialog(catalog, organization)

		await openTab(wrapper, 'Organization')

		expect(wrapper.find('.organizationInfo h3').text()).toBe('Gemeente Zuiddrecht')
		expect(wrapper.find('.organizationInfo p').text()).toBe('De gemeente')
		await wrapper.find('.organizationActions .action').trigger('click')
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('organization', organization)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(router.push).toHaveBeenCalledWith('/organizations')
	})
})

describe('ViewCatalogi footer', () => {
	it('edits, deletes and closes through its buttons', async () => {
		const wrapper = mountDialog(catalog)
		const actions = wrapper.findAll('.footer .action')

		expect(actions.map((button) => button.text())).toEqual(['Edit Catalog', 'View Catalog', 'Delete Catalog'])
		await actions[0].trigger('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('catalog')
		await actions[1].trigger('click')
		expect(router.push).toHaveBeenCalledWith('/publications/woo')
		await actions[2].trigger('click')
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteObject', { objectType: 'catalog', dialogTitle: 'Catalogus' })
		await wrapper.find('.footer .close').trigger('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith(false)
	})
})
