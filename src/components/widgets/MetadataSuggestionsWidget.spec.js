/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `MetadataSuggestionsWidget.vue`: what it reads, which
 * buttons a reader and an editor see, what Accept, Reject and Ask Hermiq send,
 * and the "filled automatically" note (woo-metadata-suggestions).
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import MetadataSuggestionsWidget from './MetadataSuggestionsWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])
const N = (app, one, many, count) => (count === 1 ? one : many).replace('%n', count)
const LIST_URL = '/apps/opencatalogi/api/publications/pub-1/metadata-suggestions'

const SUGGESTIONS = [
	{
		id: 's-1',
		field: 'wooCategory',
		value: 'infocat001',
		valueLabel: 'Wetten',
		source: 'rules',
		reason: 'One category covers the schema.',
		status: 'pending',
	},
	{
		id: 's-2',
		field: 'soortHandeling',
		value: 'ontvangst',
		valueLabel: 'ontvangst',
		source: 'hermiq',
		reason: 'Read from the document.',
		status: 'accepted',
	},
]

/**
 * Mount the widget for publication pub-1 and let its read settle.
 *
 * @param {object} answer What the list read answers.
 * @param {object} [context] The injected object context.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(answer, context = null) {
	axios.get.mockResolvedValue({ data: answer })
	const wrapper = mount(MetadataSuggestionsWidget, {
		props: { objectId: 'pub-1' },
		global: {
			mocks: { t: T, n: N },
			provide: { cnObjectContext: context },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('MetadataSuggestionsWidget', () => {
	it('shows only the pending suggestions, with source and reason, and no buttons to a reader', async () => {
		const wrapper = await mountWidget({
			suggestions: SUGGESTIONS,
			hermiq: false,
			canDecide: false,
		})

		expect(axios.get).toHaveBeenCalledWith(LIST_URL)
		expect(
			wrapper.find('[data-testid="metadata-suggestion-s-1"]').text(),
		).toContain('Wetten')
		expect(
			wrapper.find('[data-testid="metadata-suggestion-s-1"]').text(),
		).toContain('Suggested by the rules. One category covers the schema.')
		expect(
			wrapper.find('[data-testid="metadata-suggestion-s-2"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="metadata-suggestion-accept-s-1"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="metadata-suggestions-ask-hermiq"]').exists(),
		).toBe(false)
	})

	it('accepts one suggestion for an editor and reads the list again', async () => {
		const wrapper = await mountWidget({
			suggestions: SUGGESTIONS,
			hermiq: false,
			canDecide: true,
		})
		axios.post.mockResolvedValue({
			data: { suggestion: { id: 's-1', status: 'accepted' } },
		})

		await wrapper
			.find('[data-testid="metadata-suggestion-accept-s-1"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/metadata-suggestions/s-1/accept',
		)
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(
			wrapper.find('[data-testid="metadata-suggestions-message"]').text(),
		).toBe('Accepted: the value is on the publication.')
	})

	it('hands the publication, read again, to the page after an accept', async () => {
		const setObject = jest.fn()
		const context = { objectId: 'pub-1', register: '26', schema: '40', setObject }
		const wrapper = await mountWidget(
			{ suggestions: SUGGESTIONS, hermiq: false, canDecide: true },
			context,
		)
		axios.post.mockResolvedValue({
			data: { suggestion: { id: 's-1', status: 'accepted' } },
		})
		const fresh = { id: 'pub-1', summary: 'Accepted value' }
		axios.get.mockImplementation((url) =>
			Promise.resolve({
				data: url.startsWith('/apps/openregister/')
					? fresh
					: { suggestions: [], hermiq: false, canDecide: true },
			}),
		)

		await wrapper
			.find('[data-testid="metadata-suggestion-accept-s-1"]')
			.trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/26/40/pub-1')
		expect(setObject).toHaveBeenCalledWith(fresh)
	})

	it('leaves the page alone after a rejection', async () => {
		const setObject = jest.fn()
		const wrapper = await mountWidget(
			{ suggestions: SUGGESTIONS, hermiq: false, canDecide: true },
			{ objectId: 'pub-1', register: '26', schema: '40', setObject },
		)
		axios.post.mockResolvedValue({
			data: { suggestion: { id: 's-1', status: 'rejected' } },
		})

		await wrapper
			.find('[data-testid="metadata-suggestion-reject-s-1"]')
			.trigger('click')
		await flushPromises()

		expect(setObject).not.toHaveBeenCalled()
	})

	it('says why a rejection was refused, in the server words', async () => {
		const wrapper = await mountWidget({
			suggestions: SUGGESTIONS,
			hermiq: false,
			canDecide: true,
		})
		axios.post.mockRejectedValue({
			response: {
				status: 403,
				data: { message: 'You may not change this publication.' },
			},
		})

		await wrapper
			.find('[data-testid="metadata-suggestion-reject-s-1"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/opencatalogi/api/metadata-suggestions/s-1/reject',
		)
		expect(
			wrapper.find('[data-testid="metadata-suggestions-message"]').text(),
		).toBe('You may not change this publication.')
	})

	it('asks Hermiq only when the server offers it, and counts what it added', async () => {
		const wrapper = await mountWidget({
			suggestions: [],
			hermiq: true,
			canDecide: true,
		})
		expect(
			wrapper.find('[data-testid="metadata-suggestions-empty"]').exists(),
		).toBe(true)
		axios.post.mockResolvedValue({
			data: { suggestions: [{ id: 'h-1' }, { id: 'h-2' }] },
		})

		await wrapper
			.find('[data-testid="metadata-suggestions-ask-hermiq"]')
			.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(LIST_URL, { source: 'hermiq' })
		expect(
			wrapper.find('[data-testid="metadata-suggestions-message"]').text(),
		).toBe('Suggestions Hermiq added: 2')
	})

	it('names the fields a rule filled when the publication was created', async () => {
		const wrapper = await mountWidget(
			{ suggestions: [], hermiq: false, canDecide: false },
			{
				objectId: 'pub-1',
				object: {
					filledByRule: [{ field: 'organization', value: 'org-a' }],
				},
			},
		)

		expect(
			wrapper.find('[data-testid="metadata-filled-organization"]').text(),
		).toBe('Organisation: Filled automatically: the only value you can choose')
	})

	it('reads nothing while no publication id is known, and shows nothing when the read fails', async () => {
		mount(MetadataSuggestionsWidget, {
			props: { objectId: '' },
			global: { mocks: { t: T, n: N } },
		})
		await flushPromises()
		expect(axios.get).not.toHaveBeenCalled()

		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(MetadataSuggestionsWidget, {
			props: { objectId: 'pub-1' },
			global: { mocks: { t: T, n: N } },
		})
		await flushPromises()
		expect(
			wrapper.find('[data-testid="metadata-suggestions-ask-hermiq"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="metadata-suggestions-empty"]').exists(),
		).toBe(true)
	})
})
