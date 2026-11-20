/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for `PlainSummaryDraftWidget.vue`: hidden without a text assistant,
 * a draft stays a suggestion until it is accepted with a level (REQ-PPL-002).
 */

import axios from '@nextcloud/axios'
import PlainSummaryDraftWidget, { MAX_POLLS } from './PlainSummaryDraftWidget.vue'

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => ({}))

const t = (app, text) => text

/**
 * A widget state for publication p1, with its methods bound.
 *
 * @return {object} The state.
 */
function widget() {
	const self = { ...PlainSummaryDraftWidget.data(), t, resolvedObjectId: 'p1' }
	for (const [name, method] of Object.entries(PlainSummaryDraftWidget.methods)) {
		self[name] = method.bind(self)
	}
	self.schedulePoll = jest.fn()
	return self
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('PlainSummaryDraftWidget', () => {
	it('offers no draft without a text assistant, and says so once it has asked', async () => {
		const self = widget()
		expect(self.checked).toBe(false)
		axios.get.mockResolvedValue({ data: { available: false } })
		await self.checkAssistant()
		expect(self.available).toBe(false)
		expect(self.checked).toBe(true)

		axios.get.mockRejectedValue(new Error('down'))
		await self.checkAssistant()
		expect(self.available).toBe(false)

		axios.get.mockResolvedValue({ data: { available: true } })
		await self.checkAssistant()
		expect(self.available).toBe(true)
		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/plain-summary/assistant')
	})

	it('schedules a draft and polls it until there is a suggestion, without storing anything', async () => {
		const self = widget()
		axios.post.mockResolvedValue({ data: { taskId: 42 } })
		await self.startDraft()
		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/publications/p1/plain-summary-draft')
		expect(self.taskId).toBe(42)
		expect(self.schedulePoll).toHaveBeenCalledTimes(1)

		axios.get.mockResolvedValue({ data: { status: 'running' } })
		await self.poll()
		expect(axios.get).toHaveBeenCalledWith('/apps/opencatalogi/api/publications/p1/plain-summary-draft/42')
		expect(self.schedulePoll).toHaveBeenCalledTimes(2)

		axios.get.mockResolvedValue({ data: { status: 'successful', text: 'Een korte uitleg.' } })
		await self.poll()
		expect(self.suggestion).toBe('Een korte uitleg.')
		expect(self.busy).toBe(false)
		expect(axios.post).toHaveBeenCalledTimes(1)
	})

	it('accepts the edited suggestion with its level and the task it came from', async () => {
		const self = widget()
		self.taskId = 42
		self.suggestion = 'Aangepaste uitleg.'
		self.level = 'A2'
		axios.post.mockResolvedValue({ data: {} })

		await self.accept()

		expect(axios.post).toHaveBeenCalledWith('/apps/opencatalogi/api/publications/p1/plain-summary', {
			text: 'Aangepaste uitleg.',
			level: 'A2',
			fromTaskId: 42,
		})
		expect(self.suggestion).toBe('')
		expect(self.messageType).toBe('success')
	})

	it('discards a suggestion without a request', () => {
		const self = widget()
		self.suggestion = 'x'
		self.taskId = 1
		self.discard()
		expect(self.suggestion).toBe('')
		expect(self.taskId).toBe(null)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('shows a failed task, a refusal, and stops after the last poll', async () => {
		const self = widget()
		self.take({ status: 'failed', error: 'No text.' })
		expect(self.message).toBe('No text.')
		expect(self.messageType).toBe('error')

		axios.post.mockRejectedValue({ response: { data: { error: 'no-provider', message: 'No text assistant.' } } })
		await self.startDraft()
		expect(self.message).toBe('No text assistant.')
		expect(self.busy).toBe(false)

		self.polls = MAX_POLLS
		self.schedulePoll.mockClear()
		self.take({ status: 'scheduled' })
		expect(self.schedulePoll).not.toHaveBeenCalled()
		expect(self.messageType).toBe('error')
	})
})
