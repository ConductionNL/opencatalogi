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

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn(), put: jest.fn(), delete: jest.fn() }))
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

	describe('a failed table (REQ-DFA-004)', () => {
		const FAILED = { id: 't2', title: 'parkeren.csv', file: '78', status: 'failed', lastError: 'Row 350: bedrag is not a number', failedAt: '2026-11-20T10:15:00+00:00' }

		it('shows Import failed, the refused row, when it failed and Retry', async () => {
			answerReads([FAILED], [{ id: 78, name: 'parkeren.csv' }])

			const wrapper = await mountWidget()

			const failure = wrapper.find('[data-testid="publication-table-failure-t2"]')
			expect(failure.text()).toContain('Import failed')
			expect(failure.text()).toContain('Row 350: bedrag is not a number')
			expect(failure.text()).toContain('Failed on ' + new Date(FAILED.failedAt).toLocaleString())
			expect(wrapper.find('[data-testid="publication-table-retry-t2"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="publication-table-publish-78"]').exists()).toBe(false)
		})

		it('says the import failed without an error, and leaves out a time it does not have', async () => {
			answerReads([{ ...FAILED, lastError: '', failedAt: undefined }], [])

			const failure = (await mountWidget()).find('[data-testid="publication-table-failure-t2"]')

			expect(failure.text()).toContain('The import failed.')
			expect(failure.text()).not.toContain('Failed on')
		})

		it('keeps a time it cannot read as the server wrote it', async () => {
			answerReads([{ ...FAILED, failedAt: 'yesterday' }], [])

			const failure = (await mountWidget()).find('[data-testid="publication-table-failure-t2"]')

			expect(failure.text()).toContain('Failed on yesterday')
		})

		it('retries the import and reads the tables again', async () => {
			answerReads([FAILED], [])
			axios.post.mockResolvedValue({ data: { ...FAILED, status: 'ready', rowCount: 12 } })
			const wrapper = await mountWidget()

			await wrapper.find('[data-testid="publication-table-retry-t2"]').trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(TABLES_URL + '/t2/retry')
			expect(axios.get).toHaveBeenCalledTimes(4)
			expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('The table is published.')
		})

		it('names the refused row when the retry fails again, and words a refusal', async () => {
			answerReads([FAILED], [])
			axios.post
				.mockResolvedValueOnce({ data: { ...FAILED, lastError: 'Row 351: datum is not a date' } })
				.mockRejectedValueOnce({ response: { status: 403 } })
			const wrapper = await mountWidget()

			await wrapper.find('[data-testid="publication-table-retry-t2"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('Not published: Row 351: datum is not a date')

			await wrapper.find('[data-testid="publication-table-retry-t2"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('You may not change this publication.')
		})
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

	describe('views on the public page', () => {
		const VIEWS_URL = TABLES_URL + '/t1/views'
		const VIEWS = [
			{ id: 'v1', table: 't1', kind: 'table', title: 'Alle speeltuinen', columns: ['toestellen'], position: 0 },
			{ id: 'v2', table: 't1', kind: 'bar', title: 'Per wijk', x: 'toestellen', aggregate: 'count', position: 1 },
		]

		/**
		 * Answer the reads, the views of t1 included.
		 *
		 * @param {Array<object>} views The views t1 answers.
		 * @return {void}
		 */
		function answerWithViews(views) {
			axios.get.mockImplementation((url) => {
				if (url === TABLES_URL) {
					return Promise.resolve({ data: { results: [READY] } })
				}
				if (url === VIEWS_URL) {
					return Promise.resolve({ data: { table: 't1', results: views } })
				}
				return Promise.resolve({ data: { results: [] } })
			})
		}

		it('lists the saved views of a ready table in page order, with the add action', async () => {
			answerWithViews(VIEWS)

			const wrapper = await mountWidget()

			const items = wrapper.findAll('[data-testid^="publication-table-view-v"]')
			expect(items.map((item) => item.attributes('data-testid'))).toEqual(['publication-table-view-v1', 'publication-table-view-v2'])
			expect(items[0].text()).toContain('Alle speeltuinen (Table)')
			expect(items[1].text()).toContain('Per wijk (Bar chart)')
			expect(wrapper.find('[data-testid="publication-table-view-up-v1"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-testid="publication-table-view-down-v2"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-testid="publication-table-add-view-t1"]').exists()).toBe(true)
		})

		it('says there are no views yet when the table has none', async () => {
			answerWithViews([])

			const wrapper = await mountWidget()

			expect(wrapper.find('[data-testid="publication-table-views-t1"]').text()).toContain('No views yet.')
		})

		it('moves a view up by saving both views with their swapped positions', async () => {
			answerWithViews(VIEWS)
			axios.post.mockResolvedValue({ data: {} })
			const wrapper = await mountWidget()

			await wrapper.find('[data-testid="publication-table-view-up-v2"]').trigger('click')
			await flushPromises()

			expect(axios.post.mock.calls).toEqual([
				[VIEWS_URL, { kind: 'bar', title: 'Per wijk', x: 'toestellen', aggregate: 'count', position: 0, viewId: 'v2' }],
				[VIEWS_URL, { kind: 'table', title: 'Alle speeltuinen', columns: ['toestellen'], position: 1, viewId: 'v1' }],
			])
		})

		it('removes a view and reads the views again', async () => {
			answerWithViews(VIEWS)
			axios.delete.mockResolvedValue({ data: [] })
			const wrapper = await mountWidget()
			const readsBefore = axios.get.mock.calls.filter(([url]) => url === VIEWS_URL).length

			await wrapper.find('[data-testid="publication-table-view-remove-v1"]').trigger('click')
			await flushPromises()

			expect(axios.delete).toHaveBeenCalledWith(VIEWS_URL + '/v1')
			expect(axios.get.mock.calls.filter(([url]) => url === VIEWS_URL).length).toBe(readsBefore + 1)
			expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('The view "Alle speeltuinen" is removed.')
		})

		it('words a refused removal', async () => {
			answerWithViews(VIEWS)
			axios.delete.mockRejectedValue({ response: { status: 403 } })
			const wrapper = await mountWidget()

			await wrapper.find('[data-testid="publication-table-view-remove-v2"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-testid="publication-tables-message"]').text()).toBe('You may not change this publication.')
		})
	})
})
