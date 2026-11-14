/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `PublicationVisibilityWidget.vue`: what it reads when the
 * publication id is known, which buttons each state shows, what Publish sends,
 * and how each refusal the server can give is put into words.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const VISIBILITY_URL = '/apps/opencatalogi/api/publications/pub-1/visibility'
const FILES_URL = '/apps/openregister/api/objects/reg-1/pubs/pub-1/files'

/**
 * Answer the two reads the widget makes.
 *
 * @param {object} answers The visibility and files outcomes.
 * @return {void}
 */
function answerReads({ visibility, files }) {
	axios.get.mockImplementation((url) => {
		if (url === VISIBILITY_URL) {
			return visibility
		}
		if (url === FILES_URL) {
			return files
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
}

/**
 * Mount the widget for publication pub-1 and let its reads settle.
 *
 * @param {object} [props] Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(props = {}) {
	const wrapper = mount(PublicationVisibilityWidget, {
		props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs', ...props },
		global: {
			mocks: { t: T },
			stubs: { WithdrawPublicationDialog: true },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The data-testids of the buttons shown.
 *
 * @param {object} wrapper The widget.
 * @return {Array<string>} The test ids.
 */
function buttons(wrapper) {
	return ['publication-publish-now', 'publication-publish-again', 'publication-withdraw']
		.filter((id) => wrapper.find(`[data-testid="${id}"]`).exists())
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('PublicationVisibilityWidget loading', () => {
	it('reads nothing while no publication id is known', async () => {
		await mountWidget({ objectId: '' })
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads the state and the documents of the publication', async () => {
		answerReads({
			visibility: Promise.resolve({ data: { state: 'public' } }),
			files: Promise.resolve({ data: { results: [{ id: 5, title: 'Besluit' }, { id: 6, name: 'bijlage.pdf' }, { id: 7 }] } }),
		})
		const wrapper = await mountWidget()

		expect(axios.get.mock.calls.map((call) => call[0])).toEqual([VISIBILITY_URL, FILES_URL])
		expect(wrapper.vm.state).toBe('public')
		expect(wrapper.vm.documents).toEqual([
			{ id: 5, label: 'Besluit' },
			{ id: 6, label: 'bijlage.pdf' },
			{ id: 7, label: '7' },
		])
		expect(wrapper.find('[data-testid="publication-visibility-state"]').text()).toBe('Public.')
	})

	it('shows no state and no documents when both reads fail', async () => {
		answerReads({
			visibility: Promise.reject(new Error('down')),
			files: Promise.reject(new Error('down')),
		})
		const wrapper = await mountWidget()

		expect(wrapper.vm.state).toBe('')
		expect(wrapper.vm.documents).toEqual([])
		expect(wrapper.vm.stateLabel).toBe('')
		expect(buttons(wrapper)).toEqual([])
	})

	it('treats a visibility answer without a state as unknown', async () => {
		answerReads({
			visibility: Promise.resolve({ data: {} }),
			files: Promise.resolve({ data: [] }),
		})
		const wrapper = await mountWidget()
		expect(wrapper.vm.state).toBe('')
	})
})

describe('PublicationVisibilityWidget states', () => {
	const CASES = {
		draft: ['Not published yet.', ['publication-publish-now']],
		scheduled: ['Scheduled: it becomes public on its publication date.', ['publication-publish-now', 'publication-withdraw']],
		public: ['Public.', ['publication-withdraw']],
		withdrawn: ['Withdrawn: readers of the public site no longer see it.', ['publication-publish-again']],
		archived: ['Archived: kept for retention, never shown publicly again.', []],
		bogus: ['', []],
	}

	for (const [state, [label, shown]] of Object.entries(CASES)) {
		it(`labels "${state}" and shows only its buttons`, async () => {
			answerReads({
				visibility: Promise.resolve({ data: { state } }),
				files: Promise.resolve({ data: [] }),
			})
			const wrapper = await mountWidget()

			expect(wrapper.vm.stateLabel).toBe(label)
			expect(buttons(wrapper)).toEqual(shown)
		})
	}

	it('opens the withdraw dialog and clears the previous message', async () => {
		answerReads({
			visibility: Promise.resolve({ data: { state: 'public' } }),
			files: Promise.resolve({ data: [] }),
		})
		const wrapper = await mountWidget()
		wrapper.vm.message = 'old'

		wrapper.vm.openWithdraw()

		expect(wrapper.vm.dialogOpen).toBe(true)
		expect(wrapper.vm.message).toBe('')
	})
})

describe('PublicationVisibilityWidget.publish', () => {
	beforeEach(() => {
		answerReads({
			visibility: Promise.resolve({ data: { state: 'draft' } }),
			files: Promise.resolve({ data: [] }),
		})
	})

	it('posts to the publish endpoint, is busy meanwhile and then shows the publication as public', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await mountWidget()
		wrapper.vm.message = 'old'

		const pending = wrapper.vm.publish()
		expect(wrapper.vm.busy).toBe(true)
		expect(wrapper.vm.message).toBe('')
		await pending

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/publications/pub-1/publish')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.state).toBe('public')
		expect(wrapper.vm.messageType).toBe('success')
		expect(wrapper.vm.message).toBe('The publication is public.')
	})

	it('says the user may not change the publication on 403 and keeps the state', async () => {
		axios.post.mockRejectedValue({ response: { status: 403 } })
		const wrapper = await mountWidget()

		await wrapper.vm.publish()

		expect(wrapper.vm.state).toBe('draft')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.messageType).toBe('error')
		expect(wrapper.vm.message).toBe('You may not change this publication.')
	})

	it('says someone else changed it first on 409 and reads the state again', async () => {
		axios.post.mockRejectedValue({ response: { status: 409 } })
		const wrapper = await mountWidget()
		axios.get.mockClear()
		answerReads({
			visibility: Promise.resolve({ data: { state: 'public' } }),
			files: Promise.resolve({ data: [] }),
		})

		await wrapper.vm.publish()
		await flushPromises()

		expect(wrapper.vm.messageType).toBe('error')
		expect(wrapper.vm.message).toBe('Someone else changed it first. The state shown is the current one.')
		expect(axios.get.mock.calls.map((call) => call[0])).toEqual([VISIBILITY_URL, FILES_URL])
		expect(wrapper.vm.state).toBe('public')
	})

	it('asks to try again on any other failure, with or without a response', async () => {
		const wrapper = await mountWidget()

		axios.post.mockRejectedValueOnce({ response: { status: 500 } })
		await wrapper.vm.publish()
		expect(wrapper.vm.message).toBe('That did not work. Try again.')

		axios.post.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.publish()
		expect(wrapper.vm.messageType).toBe('error')
		expect(wrapper.vm.message).toBe('That did not work. Try again.')
		expect(wrapper.vm.state).toBe('draft')
	})
})
