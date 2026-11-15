/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rendered directory page: the status summary, the loading, error and
 * empty states, one row per peer with its status, sync times, error and
 * integration level, the admin-only "Sync now" action, the edit and remove
 * actions, and the pager. The page is mounted; only HTTP, the navigation
 * store and the admin check are replaced.
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { h } from 'vue'
import FederationDirectory from './FederationDirectory.vue'
import { navigationStore } from '../../store/store.js'

jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }), { virtual: true })
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path }))
jest.mock('../../composables/useIsAdmin.js', () => ({ useIsAdmin: () => ({ isAdmin: global.__isAdmin }) }))
jest.mock('../../store/store.js', () => ({ navigationStore: { modal: null, setModal: jest.fn() } }))

function button (name) {
  return {
	name,
	props: ['disabled', 'variant', 'closeAfterClick'],
	emits: ['click'],
	render() {
		return h('button', { class: name, disabled: this.disabled || undefined, onClick: () => this.$emit('click') }, this.$slots.default?.())
	},
}
}

const stubs = {
	NcButton: button('nc-button'),
	NcActionButton: button('nc-action-button'),
	NcActions: { name: 'NcActions', props: ['forceMenu', 'menuName'], render() { return h('div', { class: 'nc-actions' }, this.$slots.default?.()) } },
	NcDateTime: { name: 'NcDateTime', props: ['timestamp'], render() { return h('time', { 'data-ts': String(this.timestamp) }) } },
	CnPagination: {
		name: 'CnPagination',
		props: ['currentPage', 'totalPages', 'totalItems', 'currentPageSize'],
		emits: ['page-changed', 'page-size-changed'],
		render() { return h('nav', { class: 'pager' }) },
	},
	FederationAddDirectoryModal: true,
	FederationEditListingModal: { name: 'FederationEditListingModal', props: ['listing'], render: () => null },
	FederationDeleteListingModal: { name: 'FederationDeleteListingModal', props: ['listing'], render: () => null },
	PencilOutline: true,
	DeleteOutline: true,
	DotsHorizontal: true,
	Sync: true,
}

const UP = { id: 'l1', title: 'Gemeente A', directory: 'https://a.example/dir', statusCode: 200, integrationLevel: 'sync', lastSuccessAt: '2026-11-01T10:00:00Z', lastSync: '2026-11-01T10:00:00Z' }
const DEGRADED = { id: 'l2', directory: 'https://b.example/dir', statusCode: 404, integrationLevel: 'search', lastSuccessAt: '2026-10-01T10:00:00Z', lastSync: '2026-11-02T10:00:00Z', lastError: 'HTTP 404 from peer' }
const DOWN = { id: 'l3', available: false, integrationLevel: 'custom-level' }
const UNKNOWN = { id: 'l4', title: 'Nieuw' }

/**
 * Answer the listings request with one page.
 *
 * @param {Array<object>} results - The listings
 * @param {object} page - Paging fields
 * @return {void}
 */
function answer(results, page = {}) {
	global.fetch = jest.fn(async () => ({ ok: true, json: async () => ({ results, page: 1, pages: 1, total: results.length, limit: 20, ...page }) }))
}

/**
 * Mount the page and let its first load finish.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountPage() {
	const wrapper = mount(FederationDirectory, { global: { stubs } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	global.__isAdmin = true
	navigationStore.modal = null
	navigationStore.setModal.mockReset()
	axios.post.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
})

afterEach(() => {
	delete global.fetch
})

describe('FederationDirectory rendered', () => {
	it('counts each status in the summary and shows unknown only when there is one', async () => {
		answer([UP, { ...UP, id: 'l5' }, { ...UP, id: 'l6' }, DEGRADED, DOWN, { ...DOWN, id: 'l7' }, UNKNOWN, { ...UNKNOWN, id: 'l8' }, { ...UNKNOWN, id: 'l9' }, { ...UNKNOWN, id: 'l10' }])
		const wrapper = await mountPage()
		const items = wrapper.findAll('.federation-directory__summary-item').map((item) => item.text())

		expect(items).toEqual(['3 available', '1 degraded', '2 unreachable', '4 unknown'])

		answer([UP])
		const onlyUp = await mountPage()
		expect(onlyUp.find('.federation-directory__summary-item--unknown').exists()).toBe(false)
	})

	it('renders one row per peer with its status, name, message, sync times and integration level', async () => {
		answer([UP, DEGRADED, DOWN, UNKNOWN])
		const wrapper = await mountPage()
		const rows = wrapper.findAll('.federation-directory__node')

		expect(rows.map((row) => row.classes().find((c) => c.startsWith('federation-directory__node--')))).toEqual([
			'federation-directory__node--up',
			'federation-directory__node--degraded',
			'federation-directory__node--down',
			'federation-directory__node--unknown',
		])
		expect(rows[0].find('.federation-directory__node-name').text()).toBe('Gemeente A')
		expect(rows[0].find('.federation-directory__node-message').exists()).toBe(false)
		expect(rows[0].find('.federation-directory__field-value').exists()).toBe(true)
		expect(rows[0].findAll('time').map((t) => t.attributes('data-ts'))).toEqual([String(Date.parse(UP.lastSuccessAt))])
		expect(rows[0].text()).toContain('Full sync')
		expect(rows[0].find('.hidden-visually').text()).toMatch(/^available, last successful sync /)

		expect(rows[1].find('.federation-directory__node-name').text()).toBe('https://b.example/dir')
		expect(rows[1].find('.federation-directory__node-message').text()).toBe('HTTP status: 404')
		expect(rows[1].findAll('time').map((t) => t.attributes('data-ts'))).toEqual([
			String(Date.parse(DEGRADED.lastSuccessAt)),
			String(Date.parse(DEGRADED.lastSync)),
		])
		expect(rows[1].find('.federation-directory__node-error').text()).toBe('Error: HTTP 404 from peer')
		expect(rows[1].text()).toContain('Federated search')

		expect(rows[2].find('.federation-directory__node-name').text()).toBe('Unnamed instance')
		expect(rows[2].find('.federation-directory__node-message').text()).toBe('Peer is unreachable')
		expect(rows[2].text()).toContain('Never synchronised successfully')
		expect(rows[2].text()).toContain('custom-level')
		expect(rows[2].find('.hidden-visually').text()).toBe('unreachable, never synchronised successfully')

		expect(rows[3].text()).toContain('not set')
	})

	it('says so while loading, on an error and when there are no peers, and hides the pager on an error', async () => {
		let resolve
		global.fetch = jest.fn(() => new Promise((r) => { resolve = r }))
		const loading = mount(FederationDirectory, { global: { stubs } })
		await flushPromises()
		expect(loading.text()).toContain('Loading federated peers…')
		expect(loading.findComponent({ name: 'CnPagination' }).exists()).toBe(false)
		resolve({ ok: true, json: async () => ({ results: [] }) })
		await flushPromises()
		expect(loading.text()).toContain('No federation peers registered.')
		expect(loading.findComponent({ name: 'CnPagination' }).exists()).toBe(true)

		global.fetch = jest.fn(async () => ({ ok: false, status: 503 }))
		const failed = await mountPage()
		expect(failed.find('.federation-directory__error').text()).toBe('Failed to load directory: HTTP 503')
		expect(failed.findComponent({ name: 'CnPagination' }).exists()).toBe(false)
	})

	it('opens the add, edit and remove dialogs with the right listing', async () => {
		answer([UP])
		const wrapper = await mountPage()

		await wrapper.find('.federation-directory__header .nc-button').trigger('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('federationAddDirectory')

		const actions = wrapper.findAll('.nc-action-button')
		await actions[1].trigger('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('federationEditListing')
		expect(wrapper.findComponent({ name: 'FederationEditListingModal' }).props('listing')).toEqual(UP)

		await actions[2].trigger('click')
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('federationDeleteListing')
		expect(wrapper.findComponent({ name: 'FederationDeleteListingModal' }).props('listing')).toEqual(UP)
	})

	it('offers Sync now to an administrator only, and synchronises the peer from it', async () => {
		answer([UP])
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await mountPage()
		const sync = wrapper.findAll('.nc-action-button')[0]
		expect(sync.text()).toBe('Sync now')

		await sync.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/nc/apps/opencatalogi/api/listings/sync', { id: 'l1' })
		expect(showSuccess).toHaveBeenCalledWith('Synchronised Gemeente A')
		expect(global.fetch).toHaveBeenCalledTimes(2)

		global.__isAdmin = false
		const notAdmin = await mountPage()
		expect(notAdmin.findAll('.nc-action-button').map((b) => b.text())).toEqual(['Edit', 'Remove'])
	})

	it('pages and changes the page size through the pager', async () => {
		answer([UP], { page: 2, pages: 3, total: 41, limit: 20 })
		const wrapper = await mountPage()
		const pager = wrapper.findComponent({ name: 'CnPagination' })
		expect([pager.props('currentPage'), pager.props('totalPages'), pager.props('totalItems'), pager.props('currentPageSize')]).toEqual([2, 3, 41, 20])

		await pager.vm.$emit('page-changed', 3)
		expect(global.fetch).toHaveBeenLastCalledWith('/nc/apps/opencatalogi/api/listings?_page=3&_limit=20', expect.anything())

		await flushPromises()
		await wrapper.findComponent({ name: 'CnPagination' }).vm.$emit('page-size-changed', 50)
		expect(global.fetch).toHaveBeenLastCalledWith('/nc/apps/opencatalogi/api/listings?_page=1&_limit=50', expect.anything())
	})
})
