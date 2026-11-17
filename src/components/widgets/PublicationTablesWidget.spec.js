/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublicationTablesWidget.vue`: what it reads, what Publish
 * as table and Save dictionary send, and how a refusal is put into words.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationTablesWidget from './PublicationTablesWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn(), put: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])
const N = (app, one, many, count, vars = {}) => T(app, count === 1 ? one : many, vars)

const TABLES_URL = '/apps/opencatalogi/api/publications/pub-1/tables'
const FILES_URL = '/apps/openregister/api/objects/reg-1/pubs/pub-1/files'
const READY = {
	id: 't1',
	title: 'speeltuinen.csv',
	file: '77',
	status: 'ready',
	rowCount: 2,
	dictionary: [{ name: 'toestellen', title: 'toestellen', description: '', type: 'integer' }],
}

/**
 * Answer the two reads the widget makes.
 *
 * @param {Array<object>} tables The tables.
 * @param {Array<object>} files The attachments.
 * @return {void}
 */
function answerReads(tables, files) {
	axios.get.mockImplementation((url) => {
		if (url === TABLES_URL) {
			return Promise.resolve({ data: { results: tables } })
		}
		if (url === FILES_URL) {
			return Promise.resolve({ data: { results: files } })
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
}

/**
 * Mount the widget for publication pub-1 in catalogue speeltuinen and let its reads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget() {
	const wrapper = mount(PublicationTablesWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs', content: { catalogSlug: 'speeltuinen' } },
		global: {
			mocks: { t: T, n: N },
			stubs: { NcTextField: { props: ['modelValue', 'label'], template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' } },
		},
	})
	await flushPromises()
	return wrapper
}

describe('PublicationTablesWidget', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('shows each table with its rows, query link and dictionary, and offers the other CSVs', async () => {
		answerReads([READY], [{ id: 77, name: 'speeltuinen.csv' }, { id: 78, name: 'parkeren.csv' }, { id: 80, name: 'besluit.pdf' }])

		const wrapper = await mountWidget()

		expect(wrapper.find('[data-testid="publication-table-t1"]').text()).toContain('Rows: 2')
		expect(wrapper.find('a').attributes('href')).toBe('/apps/opencatalogi/api/speeltuinen/pub-1/tables/t1/rows')
		expect(wrapper.find('[data-testid="publication-table-publish-78"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="publication-table-publish-77"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="publication-table-publish-80"]').exists()).toBe(false)
	})

	it('publishes an attachment as a table and reads the tables again', async () => {
		answerReads([], [{ id: 78, name: 'parkeren.csv' }])
		axios.post.mockResolvedValue({ data: { id: 't2', status: 'ready' } })
		const wrapper = await mountWidget()

		await wrapper.find('[data-testid="publication-table-publish-78"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(TABLES_URL, { fileId: '78' })
		expect(axios.get).toHaveBeenCalledTimes(4)
		expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('The table is published.')
	})

	it('names the refused row when the import fails', async () => {
		answerReads([], [{ id: 78, name: 'parkeren.csv' }])
		axios.post.mockResolvedValue({ data: { status: 'failed', lastError: 'Row 350: not a number' } })
		const wrapper = await mountWidget()

		await wrapper.find('[data-testid="publication-table-publish-78"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('Not published: Row 350: not a number')
	})

	it('saves only the changed columns of the dictionary', async () => {
		answerReads([READY], [])
		const saved = [{ name: 'toestellen', title: 'toestellen', description: 'Number of play devices on the site', type: 'integer' }]
		axios.put.mockResolvedValue({ data: { table: 't1', columns: saved } })
		const wrapper = await mountWidget()

		await wrapper.find('input[aria-label="Description of toestellen"]').setValue('Number of play devices on the site')
		await wrapper.find('[data-testid="publication-table-save-t1"]').trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(TABLES_URL + '/t1/dictionary', {
			columns: [{ name: 'toestellen', title: 'toestellen', description: 'Number of play devices on the site' }],
		})
		expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('The dictionary is saved.')
	})

	it('sends nothing when nothing changed, and words a refusal', async () => {
		answerReads([READY], [{ id: 78, name: 'parkeren.csv' }])
		axios.post.mockRejectedValue({ response: { status: 403 } })
		const wrapper = await mountWidget()

		await wrapper.find('[data-testid="publication-table-save-t1"]').trigger('click')
		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('Nothing changed.')

		await wrapper.find('[data-testid="publication-table-publish-78"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('You may not change this publication.')
	})
})
