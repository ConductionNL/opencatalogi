/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the template of `PublicationDetail.vue`: the
 * publication fields, the attachment rows with their status and tags, and
 * the empty states, rendered against a mocked store. Every tab is rendered at
 * once. Theme rows are left out: their template reads an undefined
 * `themeStore`, so rendering one throws.
 */

import { flushPromises, mount } from '@vue/test-utils'
import PublicationDetail from './PublicationDetail.vue'
import { catalogStore, navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))
jest.mock('@nextcloud/vue', () => {
	const plain = (name) => ({ name, template: '<div><slot /></div>' })
	return {
		...jest.requireActual('../../../tests/mocks/nextcloud-vue.js'),
		NcActionLink: plain('NcActionLink'),
		NcListItem: plain('NcListItem'),
		NcSelectTags: plain('NcSelectTags'),
	}
})
jest.mock('../../services/usageStats.js', () => ({ fetchPublicationStats: jest.fn(() => new Promise(() => {})) }))
jest.mock('../../store/store.js', () => {
	const state = { active: {}, collections: {} }
	return {
		__state: state,
		catalogStore: { getPublicationAttachments: jest.fn(() => Promise.resolve()), fetchPublications: jest.fn() },
		navigationStore: { setModal: jest.fn(), setDialog: jest.fn() },
		objectStore: {
			getActiveObject: (type) => state.active[type] ?? null,
			setActiveObject: jest.fn((type, object) => {
				state.active[type] = object
			}),
			getCollection: (type) => state.collections[type] ?? { results: [] },
			getObject: () => null,
			fetchObject: jest.fn(() => Promise.resolve()),
			isLoading: () => false,
		},
	}
})

const { __state: store } = jest.requireMock('../../store/store.js')

/**
 * A stub that renders its name and every slot.
 *
 * @param {string} name The component name.
 * @param {string} tag The root element.
 * @return {object} The stub.
 */
function slot(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'title'],
		template: `<${tag} class="${name}" :data-name="name ?? title"><slot name="icon" /><slot name="subname" /><slot name="indicator" /><slot name="actions" /><slot name="extra" /><slot /></${tag}>`,
	}
}

const stubs = {
	AppTabs: slot('AppTabs'),
	AppTab: slot('AppTab', 'section'),
	NcActions: slot('NcActions'),
	NcActionButton: { ...slot('NcActionButton', 'button'), emits: ['click'], template: '<button class="NcActionButton" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcActionLink: slot('NcActionLink', 'a'),
	NcListItem: slot('NcListItem'),
	NcSelectTags: slot('NcSelectTags'),
	NcSelect: slot('NcSelect'),
	NcCheckboxRadioSwitch: slot('NcCheckboxRadioSwitch'),
	NcCounterBubble: slot('NcCounterBubble'),
	CnPagination: slot('CnPagination'),
	PublicationStatsPanel: slot('PublicationStatsPanel'),
}

const PUBLICATION = {
	id: 'pub-1',
	title: 'Annual report',
	reference: 'REF-1',
	summary: 'A summary',
	description: 'A description',
	license: 'CC0',
	status: 'Published',
	published: '2026-01-01',
	modified: '2026-02-01',
	themes: [],
	data: { colour: 'blue' },
	'@self': { register: 'r1', schema: 's1' },
}

/**
 * Mount the page.
 *
 * @return {Promise<object>} The wrapper, after mounting settles.
 */
async function mountPage() {
	const wrapper = mount(PublicationDetail, {
		global: {
			stubs,
			mocks: { t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) },
			// `mocks` land after `mounted` has run, so the route goes in as a global property.
			config: { globalProperties: { $route: { params: { id: 'pub-1' } } } },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
	store.active = { publication: PUBLICATION }
	store.collections = {
		publicationAttachments: {
			results: [
				{ id: 1, title: 'draft.pdf', published: null, labels: ['tag-a'], accessUrl: 'https://a', downloadUrl: 'https://d', size: 2048 },
				{ id: 2, title: 'final.pdf', published: '2026-01-02', labels: [], size: 10 },
			],
		},
		theme: { results: [{ id: 't1', title: 'Environment', summary: 'Green' }] },
	}
	global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve(['tag-a', 'tag-b']) }))
})

afterEach(() => {
	delete global.fetch
})

describe('PublicationDetail template', () => {
	it('shows the publication title and its fields', async () => {
		const wrapper = await mountPage()

		expect(wrapper.find('h1').text()).toBe('Annual report')
		for (const value of ['REF-1', 'A summary', 'A description', 'CC0', 'Published']) {
			expect(wrapper.text()).toContain(value)
		}
	})

	it('loads the attachments and the tag options on mount', async () => {
		const wrapper = await mountPage()

		expect(catalogStore.getPublicationAttachments).toHaveBeenCalled()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/tags', { method: 'get' })
		expect(wrapper.vm.labelOptionsEdit.options).toEqual(['tag-a', 'tag-b'])
	})

	it('lists one row per attachment with its tags', async () => {
		const wrapper = await mountPage()
		const names = wrapper.findAll('.NcListItem').map((item) => item.attributes('data-name'))

		expect(names).toEqual(expect.arrayContaining(['draft.pdf', 'final.pdf']))
		expect(wrapper.text()).toContain('tag-a')
	})

	it('opens the upload modal from the attachments tab button', async () => {
		const wrapper = await mountPage()
		const button = wrapper.find('.AppTab button[aria-label="Add attachment"]')

		await button.trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith('uploadFiles')
	})

	it('shows the empty states without attachments, themes or data', async () => {
		store.active.publication = { ...PUBLICATION, themes: [], data: {} }
		store.collections.publicationAttachments = { results: [] }
		const wrapper = await mountPage()

		expect(wrapper.findAll('.NcListItem')).toHaveLength(0)
		expect(wrapper.text()).toContain('No attachments')
	})
})
