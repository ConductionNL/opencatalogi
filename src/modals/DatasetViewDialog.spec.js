/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `DatasetViewDialog.vue`: what a save sends, that an
 * incomplete form sends nothing, how a refusal reads, and the preview of the
 * saved view's public data.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import DatasetViewDialog from './DatasetViewDialog.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])
const N = (app, one, many, count, vars = {}) => T(app, count === 1 ? one : many, vars)

const TABLE = {
	id: 't1',
	title: 'speeltuinen.csv',
	dictionary: [{ name: 'wijk', type: 'string' }, { name: 'toestellen', type: 'integer' }],
}
const SAVE_URL = '/apps/opencatalogi/api/publications/pub-1/tables/t1/views'

const stubs = {
	NcModal: { template: '<div><slot /></div>' },
	NcNoteCard: { props: ['type'], template: '<div :class="\'note-\' + type"><slot /></div>' },
	NcButton: { props: ['type', 'disabled'], template: '<button :type="type || \'button\'" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { props: ['modelValue', 'label'], template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
	NcSelect: { props: ['modelValue', 'inputLabel', 'options'], template: '<div class="select" :data-label="inputLabel" />' },
}

/**
 * Mount the dialog open on table t1 of publication pub-1.
 *
 * @param {object} [props] Extra props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(DatasetViewDialog, {
		props: { open: true, publicationId: 'pub-1', catalogSlug: 'speeltuinen', table: TABLE, position: 3, ...props },
		global: { mocks: { t: T, n: N }, stubs },
	})
}

describe('DatasetViewDialog', () => {
	beforeEach(() => {
		jest.clearAllMocks()
	})

	it('labels every column picker of a chart, and offers only numeric columns to combine', async () => {
		const wrapper = mountDialog({ view: { id: 'v1', kind: 'bar', title: 'Per wijk', x: 'wijk', y: 'toestellen', aggregate: 'sum' } })

		const labels = wrapper.findAll('.select').map((select) => select.attributes('data-label'))
		expect(labels).toEqual(['Show as', 'Group by (horizontal axis)', 'Value per group', 'Numeric column to combine'])
		expect(wrapper.findComponent('[data-testid="dataset-view-y"]').props('options')).toEqual(['toestellen'])
		expect(wrapper.findComponent('[data-testid="dataset-view-x"]').props('options')).toEqual(['wijk', 'toestellen'])
	})

	it('sends nothing for a form without a title, and says what is missing', async () => {
		const wrapper = mountDialog()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="dataset-view-error"]').text()).toBe('Give the view a title.')
	})

	it('saves a changed view by its id and position, then previews its chart and warns when it is truncated', async () => {
		axios.post.mockResolvedValue({ data: { id: 'v1', kind: 'bar', title: 'Per wijk' } })
		axios.get.mockResolvedValue({ data: { points: [{ x: 'Noord', value: 3 }, { x: 'Zuid', value: 5 }], truncated: true } })
		const wrapper = mountDialog({ view: { id: 'v1', kind: 'bar', title: 'Per wijk', x: 'wijk', aggregate: 'count', position: 1 } })

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(SAVE_URL, { kind: 'bar', title: 'Per wijk', x: 'wijk', aggregate: 'count', position: 1, viewId: 'v1' })
		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/speeltuinen/pub-1/tables/t1/views/v1/data')
		expect(wrapper.emitted('saved')[0]).toEqual([{ id: 'v1', kind: 'bar', title: 'Per wijk' }])
		expect(wrapper.find('[data-testid="dataset-view-preview"]').text()).toContain('Groups in the chart: 2')
		expect(wrapper.find('[data-testid="dataset-view-truncated"]').exists()).toBe(true)
	})

	it('adds a new table view at the given position, and previews its first page', async () => {
		axios.post.mockResolvedValue({ data: { id: 'v9' } })
		axios.get.mockResolvedValue({ data: { results: [{ wijk: 'Noord' }], total: 12 } })
		const wrapper = mountDialog()

		await wrapper.find('input[aria-label="Title readers see"]').setValue('Alle speeltuinen')
		wrapper.vm.form.columns = ['wijk']
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(SAVE_URL, { kind: 'table', title: 'Alle speeltuinen', columns: ['wijk'], position: 3 })
		expect(wrapper.find('[data-testid="dataset-view-preview"]').text()).toContain('Rows on the first page: 1 of 12')
	})

	it('shows the server\'s refusal and previews nothing', async () => {
		axios.post.mockRejectedValue({ response: { status: 400, data: { message: 'The column "x" is not numeric.' } } })
		const wrapper = mountDialog({ view: { id: 'v2', kind: 'map', title: 'Kaart', latitude: 'wijk', longitude: 'toestellen' } })

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="dataset-view-error"]').text()).toBe('The column "x" is not numeric.')
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="dataset-view-preview"]').exists()).toBe(false)
	})

	it('saves without a preview when the page has no catalogue', async () => {
		axios.post.mockResolvedValue({ data: { id: 'v3' } })
		const wrapper = mountDialog({ catalogSlug: '', view: { id: 'v3', kind: 'map', title: 'Kaart', latitude: 'toestellen', longitude: 'toestellen' } })

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.get).not.toHaveBeenCalled()
	})
})
