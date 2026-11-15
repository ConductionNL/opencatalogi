/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the click handlers in the template of
 * `PublicationDetail.vue`: the publication menu (help, edit, copy, publish,
 * depublish, download, delete), the attachment toolbar (open folder, bulk
 * publish), the per-attachment actions (publish, depublish, delete, edit and
 * save tags) and the attachment pager, rendered against a mocked store.
 */

import { flushPromises, mount } from '@vue/test-utils'
import PublicationDetail from './PublicationDetail.vue'
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'

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
jest.mock('@conduction/nextcloud-vue', () => ({
	...jest.requireActual('../../../tests/mocks/conduction-nextcloud-vue.js'),
	CnPagination: { name: 'CnPagination', template: '<div />' },
}))
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
	NcActionButton: {
		name: 'NcActionButton',
		emits: ['click'],
		template: '<button class="NcActionButton" @click="$emit(\'click\')"><slot /></button>',
	},
	NcButton: {
		name: 'NcButton',
		inheritAttrs: false,
		emits: ['click'],
		template: '<button class="NcButton" :aria-label="$attrs[\'aria-label\']" @click="$emit(\'click\')"><slot /></button>',
	},
	NcActionLink: slot('NcActionLink', 'a'),
	NcListItem: slot('NcListItem'),
	NcSelectTags: slot('NcSelectTags'),
	NcSelect: slot('NcSelect'),
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		inheritAttrs: false,
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" class="NcCheckboxRadioSwitch" :aria-label="$attrs[\'aria-label\']" :checked="modelValue" @change="$emit(\'update:modelValue\', !modelValue)" />',
	},
	NcCounterBubble: slot('NcCounterBubble'),
	CnPagination: {
		name: 'CnPagination',
		props: ['currentPage', 'currentPageSize'],
		emits: ['pageChanged', 'pageSizeChanged'],
		template: '<div class="CnPagination" />',
	},
	PublicationStatsPanel: slot('PublicationStatsPanel'),
}

const DRAFT = { id: 1, title: 'draft.pdf', published: null, labels: ['tag-a'], size: 2048 }
const FINAL = { id: 2, title: 'final.pdf', published: '2026-01-02', labels: [], size: 10 }

/**
 * A publication with optional `@self` overrides.
 *
 * @param {object} self Extra `@self` fields.
 * @return {object} The publication.
 */
function publication(self = {}) {
	return {
		id: 'pub-1',
		title: 'Annual report',
		themes: [],
		data: {},
		'@self': { register: 'r1', schema: 's1', folder: '/f/annual', ...self },
	}
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
			config: { globalProperties: { $route: { params: { id: 'pub-1' } } } },
		},
	})
	await flushPromises()
	jest.clearAllMocks()
	return wrapper
}

/**
 * The first button whose text is exactly `text`, optionally inside `scope`.
 *
 * @param {object} wrapper The mounted page or a part of it.
 * @param {string} text The button text.
 * @return {object} The button wrapper.
 */
function button(wrapper, text) {
	const found = wrapper.findAll('button').find((candidate) => candidate.text() === text)
	if (!found) {
		throw new Error(`no button "${text}"`)
	}
	return found
}

/**
 * The list row of one attachment, including its checkbox sibling.
 *
 * @param {object} wrapper The mounted page.
 * @param {string} title The attachment title.
 * @return {object} The row wrapper.
 */
function row(wrapper, title) {
	return wrapper.findAll('.checkedItem').find((candidate) => candidate.find(`[data-name="${title}"]`).exists())
}

beforeEach(() => {
	store.active = { publication: publication({ published: null }) }
	store.collections = { publicationAttachments: { results: [DRAFT, FINAL] }, theme: { results: [] } }
	global.fetch = jest.fn(() => Promise.resolve({ json: () => Promise.resolve({ id: 'pub-1', title: 'Annual report' }) }))
	window.open = jest.fn()
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	console.error.mockRestore()
	delete global.fetch
})

describe('PublicationDetail publication menu', () => {
	it('opens the help page in a new tab', async () => {
		const wrapper = await mountPage()

		await button(wrapper, 'Help').trigger('click')

		expect(window.open).toHaveBeenCalledWith('https://opencatalogi.conduction.nl/docs/Users/publicaties/', '_blank')
	})

	it('opens the edit modal and the copy, download and delete dialogs', async () => {
		const wrapper = await mountPage()

		await button(wrapper, 'Edit').trigger('click')
		await button(wrapper, 'Copy').trigger('click')
		await button(wrapper, 'Download').trigger('click')
		await button(wrapper.find('.NcActions'), 'Delete').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith('objectModal')
		expect(navigationStore.setDialog).toHaveBeenCalledWith('copyPublication')
		expect(navigationStore.setDialog).toHaveBeenCalledWith('downloadPublication')
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteObject', {
			objectType: 'publication',
			dialogTitle: 'Publicatie',
		})
	})

	it('publishes an unpublished publication and stores the answer as active', async () => {
		const wrapper = await mountPage()
		const menu = wrapper.find('.NcActions')

		expect(menu.findAll('button').map((b) => b.text())).not.toContain('Depublish')
		await button(menu, 'Publish').trigger('click')
		await flushPromises()

		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r1/s1/pub-1/publish', {
			method: 'POST',
			headers: { requesttoken: 'token-1' },
		})
		expect(catalogStore.fetchPublications).toHaveBeenCalled()
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', { id: 'pub-1', title: 'Annual report' })
	})

	it('offers depublish, not publish, on a published publication', async () => {
		store.active.publication = publication({ published: '2026-01-01' })
		const wrapper = await mountPage()
		const menu = wrapper.find('.NcActions')

		expect(menu.findAll('button').map((b) => b.text())).not.toContain('Publish')
		await button(menu, 'Depublish').trigger('click')

		expect(global.fetch.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/r1/s1/pub-1/depublish')
	})
})

describe('PublicationDetail attachment toolbar', () => {
	it('opens the publication folder in a new tab', async () => {
		const wrapper = await mountPage()

		await wrapper.find('button[aria-label="Open folder"]').trigger('click')

		expect(window.open).toHaveBeenCalledWith('/f/annual', '_blank')
	})

	it('selects and deselects an attachment by its checkbox', async () => {
		const wrapper = await mountPage()
		const checkbox = () => row(wrapper, 'draft.pdf').find('input.NcCheckboxRadioSwitch')

		await checkbox().trigger('change')
		expect(wrapper.vm.selectedAttachments).toEqual([1])
		expect(checkbox().element.checked).toBe(true)

		await checkbox().trigger('change')
		expect(wrapper.vm.selectedAttachments).toEqual([])
	})

	it('follows the pager to another page size', async () => {
		const wrapper = await mountPage()
		const pager = wrapper.findComponent(stubs.CnPagination)

		pager.vm.$emit('pageSizeChanged', '50')
		await flushPromises()

		expect(wrapper.vm.limit).toBe(50)
		expect(pager.props('currentPageSize')).toBe(50)
	})
})

describe('PublicationDetail attachment actions', () => {
	it('publishes a draft attachment and depublishes a published one', async () => {
		const wrapper = await mountPage()

		expect(row(wrapper, 'draft.pdf').findAll('button').map((b) => b.text())).not.toContain('Depublish')
		await button(row(wrapper, 'draft.pdf'), 'Publish').trigger('click')
		await button(row(wrapper, 'final.pdf'), 'Depublish').trigger('click')
		await flushPromises()

		const urls = global.fetch.mock.calls.map((call) => call[0])
		expect(urls).toEqual([
			'/index.php/apps/openregister/api/objects/r1/s1/pub-1/files/1/publish',
			'/index.php/apps/openregister/api/objects/r1/s1/pub-1/files/2/depublish',
		])
		expect(catalogStore.getPublicationAttachments).toHaveBeenCalledTimes(2)
	})

	it('asks to confirm deleting an attachment', async () => {
		const wrapper = await mountPage()

		await button(row(wrapper, 'final.pdf'), 'Delete').trigger('click')

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publicationAttachment', FINAL)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteAttachment')
	})

	it('edits tags, then stops editing without saving', async () => {
		const wrapper = await mountPage()

		await button(row(wrapper, 'draft.pdf'), 'Edit tags').trigger('click')
		expect(wrapper.vm.editingTags).toBe('draft.pdf')
		expect(wrapper.vm.editedTags).toEqual(['tag-a'])

		await button(row(wrapper, 'draft.pdf'), 'Stop editing tags').trigger('click')

		expect(wrapper.vm.editingTags).toBeNull()
		expect(wrapper.vm.editedTags).toEqual([])
		expect(global.fetch).not.toHaveBeenCalled()
	})

	it('saves the edited tags of an attachment', async () => {
		const wrapper = await mountPage()

		await button(row(wrapper, 'draft.pdf'), 'Edit tags').trigger('click')
		row(wrapper, 'draft.pdf').findComponent({ name: 'NcSelect' }).vm.$emit('option:created', 'tag-b')
		await flushPromises()
		expect(wrapper.vm.labelOptionsEdit.options).toContain('tag-b')
		await row(wrapper, 'draft.pdf').find('button[aria-label="Save tags for draft.pdf"]').trigger('click')
		await flushPromises()

		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r1/s1/pub-1/files/1', {
			method: 'PUT',
			body: JSON.stringify({ tags: ['tag-a', 'tag-b'] }),
			headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
		})
		expect(wrapper.vm.editingTags).toBeNull()
		expect(wrapper.vm.saveTagsLoading).toEqual([])
	})
})
