/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the template of `DirectorySideBar.vue`: the empty state off
 * the directory route or without a listing, the details of a listing with and
 * without an organization and a last error, the configuration switches and
 * the synchronise button, and the publication types: owned ones as a check,
 * others as a switch that toggles the type.
 */

import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import DirectorySideBar from './DirectorySideBar.vue'

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'test-request-token' }))

jest.mock('@nextcloud/vue', () => ({
	NcAppSidebar: {
		props: { name: { type: String, default: '' }, subname: { type: String, default: '' } },
		template: '<div class="sidebar" :data-name="name" :data-subname="subname"><slot /></div>',
	},
	NcAppSidebarTab: {
		props: { id: { type: String, default: '' }, name: { type: String, default: '' } },
		template: '<section class="tab" :id="id" :data-name="name"><slot name="icon" /><slot /></section>',
	},
	NcEmptyContent: {
		props: { name: { type: String, default: '' }, description: { type: String, default: '' } },
		template: '<div class="empty" :data-name="name">{{ description }}<slot name="icon" /><slot name="action" /></div>',
	},
	NcButton: {
		props: { disabled: Boolean, variant: { type: String, default: '' } },
		emits: ['click'],
		template: '<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		props: { modelValue: { type: Boolean, default: false } },
		emits: ['update:modelValue'],
		template: '<label class="switch" :data-on="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></label>',
	},
	NcLoadingIcon: { template: '<span class="loading" />' },
}))

const mockStore = reactive({ listing: null })
const mockNavigation = { setModal: jest.fn() }

jest.mock('../../store/store.js', () => ({
	navigationStore: { setModal: (...args) => mockNavigation.setModal(...args) },
	objectStore: {
		getActiveObject: () => mockStore.listing,
		getCollection: () => ({ results: [] }),
		fetchCollection: jest.fn(),
		createObject: jest.fn(() => Promise.resolve({})),
		deleteObject: jest.fn(() => Promise.resolve({})),
	},
}))

const { objectStore, navigationStore } = jest.requireMock('../../store/store.js')

/**
 * Mount the sidebar on a route.
 *
 * @param {string} path The route path.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountOn(path = '/directory') {
	return mount(DirectorySideBar, {
		global: {
			mocks: { $route: { path }, t: (app, text) => text, objectStore, navigationStore },
			stubs: {
				CertificateOutline: true,
				Check: true,
				CogOutline: true,
				DatabaseSyncOutline: true,
				FileTreeOutline: true,
				HelpCircleOutline: true,
				InformationSlabSymbol: true,
				LayersOutline: true,
				Plus: true,
			},
		},
	})
}

/**
 * A full listing.
 *
 * @param {object} extra Fields to add or override.
 * @return {object}
 */
function listing(extra = {}) {
	return {
		id: 'listing-7',
		title: 'Gemeente Demo',
		organization: { title: 'Demo Org' },
		summary: 'A summary',
		status: 'active',
		lastSync: '2026-11-01',
		lastSuccessAt: '2026-11-01',
		directory: 'https://dir.example',
		search: 'https://search.example',
		description: 'A description',
		available: true,
		default: false,
		publicationTypes: [],
		publicationType: [],
		...extra,
	}
}

beforeEach(() => {
	jest.clearAllMocks()
	mockStore.listing = null
	global.fetch = jest.fn(() => Promise.resolve({}))
	window.open = jest.fn()
})

afterEach(() => {
	delete global.fetch
})

describe('DirectorySideBar template', () => {
	it('shows the empty state without a listing, and its buttons read a directory and open the docs', async () => {
		const wrapper = mountOn()

		expect(wrapper.find('.sidebar').attributes('data-name')).toBe('No listing')
		expect(wrapper.find('.empty').text()).toContain('No listing selected.')
		expect(wrapper.findAll('.tab')).toHaveLength(0)

		const [read, docs] = wrapper.findAll('.empty button')
		await read.trigger('click')
		expect(mockNavigation.setModal).toHaveBeenCalledWith('addDirectory')
		await docs.trigger('click')
		expect(window.open).toHaveBeenCalledWith('https://opencatalogi.conduction.nl/docs/Administrator/directory/', '_blank')
	})

	it('shows the empty state for a listing off the directory route', () => {
		mockStore.listing = listing()

		const wrapper = mountOn('/catalogi')

		expect(wrapper.find('.empty').exists()).toBe(true)
		expect(wrapper.findAll('.tab')).toHaveLength(0)
		expect(wrapper.find('.sidebar').attributes('data-name')).toBe('Gemeente Demo')
		expect(wrapper.find('.sidebar').attributes('data-subname')).toBe('Demo Org')
	})

	it('shows the details, configuration and publication-type tabs of a listing', () => {
		mockStore.listing = listing()

		const wrapper = mountOn()

		expect(wrapper.find('.empty').exists()).toBe(false)
		expect(wrapper.findAll('.tab').map((tab) => tab.attributes('id'))).toEqual(['detail-tab', 'settings-tab', 'metdata-tab'])
		const details = wrapper.find('#detail-tab').text()
		expect(details).toContain('This organization is not validated with a certificate.')
		expect(details).not.toContain('This listing has no organization.')
		expect(details).toContain('A summary')
		expect(details).toContain('https://search.example')
		expect(details).not.toContain('Last synchronisation error:')
	})

	it('says a listing has no organization, was never synchronised and shows its last error', () => {
		mockStore.listing = listing({ organization: null, lastSuccessAt: null, lastError: 'peer timed out' })

		const details = mountOn().find('#detail-tab').text()

		expect(details).toContain('This listing has no organization.')
		expect(details).toContain('Never synchronised successfully')
		expect(details).toContain('Last synchronisation error:')
		expect(details).toContain('peer timed out')
	})

	it('binds the two switches to the listing and synchronises it from the button', async () => {
		mockStore.listing = listing()
		const wrapper = mountOn()
		const [available, takesPart] = wrapper.findAll('#settings-tab .switch')

		expect(available.attributes('data-on')).toBe('true')
		expect(takesPart.attributes('data-on')).toBe('false')
		await takesPart.trigger('click')
		expect(mockStore.listing.default).toBe(true)

		// A synchronisation still on its way shows the spinner and disables the button.
		global.fetch = jest.fn(() => new Promise(() => {}))
		await wrapper.find('#settings-tab button').trigger('click')
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/listings/synchronise/listing-7', { method: 'GET' })
		expect(wrapper.find('#settings-tab .loading').exists()).toBe(true)
		expect(wrapper.find('#settings-tab button').attributes('disabled')).toBeDefined()
	})

	it('shows owned publication types as a check and others as a switch that toggles the type', async () => {
		mockStore.listing = listing({
			publicationTypes: [
				{ title: 'Besluit', owner: true },
				{ source: 'https://peer/types/2', listed: false },
				'Plain',
			],
		})
		const wrapper = mountOn()
		const tab = wrapper.find('#metdata-tab')

		expect(tab.findAll('.publication-type-item')).toHaveLength(1)
		expect(tab.text()).toContain('Besluit')
		const switches = tab.findAll('.switch')
		expect(switches.map((s) => s.text())).toEqual(['https://peer/types/2', 'Plain'])

		await switches[0].trigger('click')
		expect(mockStore.listing.publicationTypes[1].listed).toBe(true)
		expect(global.fetch).toHaveBeenCalledWith(
			'/index.php/apps/opencatalogi/api/publication_types/synchronise',
			expect.objectContaining({ method: 'POST' }),
		)
	})
})
