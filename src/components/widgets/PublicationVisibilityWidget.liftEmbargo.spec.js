/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lift embargo on the publication page: shown on a scheduled publication only
 * to a caller the server says may lift it, which then posts the reason to
 * `lift-embargo`; Publish now is not offered on a scheduled publication
 * (REQ-PSG-002, REQ-PPW-002).
 *
 * @spec openspec/changes/publication-schedule-guards/tasks.md#task-2.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'

jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
	post: jest.fn(),
	delete: jest.fn(),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const VISIBILITY_URL = '/apps/opencatalogi/api/publications/pub-1/visibility'
const LIFT_URL = '/apps/opencatalogi/api/publications/pub-1/lift-embargo'

/**
 * Mount the widget for publication pub-1 with the given visibility answer.
 *
 * @param {object} visibility The server's visibility answer.
 * @return {Promise<object>} The wrapper.
 */
async function mountWidget(visibility) {
	axios.get.mockImplementation((url) => {
		if (url === VISIBILITY_URL) {
			return Promise.resolve({ data: visibility })
		}
		return Promise.resolve({ data: [] })
	})
	const wrapper = mount(PublicationVisibilityWidget, {
		props: {
			objectId: 'pub-1',
			register: 'reg-1',
			schema: 'pubs',
			content: { title: 'Besluit parkeren' },
		},
		global: {
			mocks: { t: T },
			stubs: {
				WithdrawPublicationDialog: true,
				RepublishDocumentDialog: true,
				DeleteDraftPermanentlyDialog: true,
				PublishWithoutDocumentsDialog: true,
				ReasonDialog: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('PublicationVisibilityWidget lifting an embargo', () => {
	it('offers Lift embargo, not Publish now, on a scheduled publication to a caller who may lift', async () => {
		const wrapper = await mountWidget({
			state: 'scheduled',
			canPublish: true,
			canUpdate: true,
			canLiftEmbargo: true,
		})

		expect(
			wrapper.find('[data-testid="publication-lift-embargo"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="publication-publish-now"]').exists(),
		).toBe(false)
	})

	it('hides both from a scheduled publication without the lift right', async () => {
		const wrapper = await mountWidget({
			state: 'scheduled',
			canPublish: true,
			canUpdate: true,
			canLiftEmbargo: false,
		})

		expect(
			wrapper.find('[data-testid="publication-lift-embargo"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="publication-publish-now"]').exists(),
		).toBe(false)
	})

	it('offers no lift on a publication that is not scheduled', async () => {
		const wrapper = await mountWidget({
			state: 'approved',
			canPublish: true,
			canLiftEmbargo: true,
		})

		expect(
			wrapper.find('[data-testid="publication-lift-embargo"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="publication-publish-now"]').exists(),
		).toBe(true)
	})

	it('names the publication in the lift dialog', async () => {
		const wrapper = await mountWidget({ state: 'scheduled', canLiftEmbargo: true })

		expect(wrapper.vm.liftHint).toBe(
			'Publish "Besluit parkeren" now, before its publication date? The lift is recorded in the audit trail with your name and the reason.',
		)
	})

	it('opens the dialog and clears the previous message', async () => {
		const wrapper = await mountWidget({
			state: 'scheduled',
			canLiftEmbargo: true,
		})
		wrapper.vm.message = 'old'

		await wrapper
			.find('[data-testid="publication-lift-embargo"]')
			.trigger('click')

		expect(wrapper.vm.liftOpen).toBe(true)
		expect(wrapper.vm.message).toBe('')
	})

	it('posts the reason and says the publication is public', async () => {
		const wrapper = await mountWidget({
			state: 'scheduled',
			canLiftEmbargo: true,
		})
		wrapper.vm.liftOpen = true
		axios.post.mockResolvedValue({
			data: { publicationDate: '2026-11-24T10:00:00+00:00' },
		})

		await wrapper.vm.liftEmbargo({ reason: 'Raad heeft het besluit vervroegd' })

		expect(axios.post).toHaveBeenCalledWith(LIFT_URL, {
			reason: 'Raad heeft het besluit vervroegd',
		})
		expect(wrapper.vm.liftOpen).toBe(false)
		expect(wrapper.vm.state).toBe('public')
		expect(wrapper.vm.messageType).toBe('success')
		expect(wrapper.vm.message).toBe(
			'The embargo was lifted. The publication is public.',
		)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reports a refusal and keeps the state', async () => {
		const wrapper = await mountWidget({
			state: 'scheduled',
			canLiftEmbargo: true,
		})
		wrapper.vm.liftOpen = true
		axios.post.mockRejectedValue({
			response: { status: 403, data: { error: 'not-allowed-to-lift' } },
		})

		await wrapper.vm.liftEmbargo({ reason: 'Vervroegd' })

		expect(wrapper.vm.liftOpen).toBe(false)
		expect(wrapper.vm.state).toBe('scheduled')
		expect(wrapper.vm.messageType).toBe('error')
		expect(wrapper.vm.message).toBe('You may not change this publication.')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reads a failed visibility call as no lift right', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(PublicationVisibilityWidget, {
			props: { objectId: 'pub-1', register: 'reg-1', schema: 'pubs' },
			global: {
				mocks: { t: T },
				stubs: { WithdrawPublicationDialog: true, ReasonDialog: true },
			},
		})
		wrapper.vm.canLiftEmbargo = true
		await flushPromises()

		expect(wrapper.vm.canLiftEmbargo).toBe(false)
	})
})
