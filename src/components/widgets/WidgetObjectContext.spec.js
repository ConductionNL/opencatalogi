/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for how the detail-page widgets find the current
 * object (the injected context in its three shapes, the props and the
 * content blob), and for what the Announce and Withdraw actions send and
 * show for every answer the server can give.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import AuditTrailWidget from './AuditTrailWidget.vue'
import NationalAnnounceWidget from './NationalAnnounceWidget.vue'
import PublicationAttachmentsWidget from './PublicationAttachmentsWidget.vue'
import PublicationVisibilityWidget from './PublicationVisibilityWidget.vue'
import ThemePreviewWidget from './ThemePreviewWidget.vue'

jest.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'admin', isAdmin: true }) }))
jest.mock('@nextcloud/axios', () => ({ get: jest.fn(), post: jest.fn() }))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/{(\w+)}/g, (_, key) => params[key]),
}))

const T = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => vars[key])

const STUBS = {
	CnAuditTrailCard: true,
	CnFilesCard: true,
	CnThemePreview: true,
	NcSelect: true,
	NcTextField: true,
	NcButton: true,
	NcNoteCard: true,
	NcLoadingIcon: true,
	NcDialog: true,
	WithdrawPublicationDialog: true,
}

const CONTEXT = {
	objectId: 'ctx-id',
	register: 'ctx-reg',
	schema: { slug: 'ctx-slug', id: 'ctx-schema-id' },
	object: { id: 'obj-id', '@self': { register: 'obj-reg', schema: 'obj-schema' } },
}

const WORLDS = {
	nothing: { provide: {}, props: {} },
	'plain context': { provide: { cnObjectContext: CONTEXT }, props: {} },
	'ref context': { provide: { cnObjectContext: { value: CONTEXT } }, props: {} },
	'empty ref context': { provide: { cnObjectContext: { value: null }, cnDetailObjectContext: { value: { objectId: 'holder-id', schema: 'holder-slug' } } }, props: {} },
	'detail holder': { provide: { cnDetailObjectContext: { value: { objectId: 'holder-id', register: 'holder-reg', schema: { name: 'holder-name' } } } }, props: {} },
	'both': { provide: { cnObjectContext: { register: 'inj-reg' }, cnDetailObjectContext: { value: { objectId: 'holder-id' } } }, props: {} },
	'props win': { provide: { cnObjectContext: CONTEXT }, props: { objectId: 'prop-id', register: 'prop-reg', schema: 'prop-schema' } },
	'schema object prop': { provide: {}, props: { schema: { id: 7 } } },
	'content blob': { provide: {}, props: { content: { objectId: 'c-id', register: 'c-reg', schema: 'c-schema', title: 'C title' } } },
}

const WIDGETS = {
	AuditTrailWidget: [AuditTrailWidget, ['ctx', 'resolvedObjectId', 'resolvedRegister', 'resolvedSchema', 'resolvedTitle']],
	NationalAnnounceWidget: [NationalAnnounceWidget, ['ctx', 'resolvedObjectId', 'resolvedRegister', 'resolvedSchema']],
	PublicationVisibilityWidget: [PublicationVisibilityWidget, ['ctx', 'resolvedObjectId']],
	PublicationAttachmentsWidget: [PublicationAttachmentsWidget, ['ctx', 'resolvedObjectId', 'resolvedRegister', 'resolvedSchema']],
	ThemePreviewWidget: [ThemePreviewWidget, ['ctx', 'theme']],
}

/**
 * Mount a widget in a world.
 *
 * @param {object} component The widget.
 * @param {object} world The provided context and the props.
 * @return {object} The wrapper.
 */
function mountIn(component, world) {
	return mount(component, {
		props: world.props,
		global: { provide: world.provide, mocks: { t: T }, stubs: STUBS },
	})
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.get.mockResolvedValue({ data: {} })
})

describe('widget object context', () => {
	for (const [name, [component, keys]] of Object.entries(WIDGETS)) {
		it(`${name} resolves the current object in every world`, () => {
			const resolved = {}
			for (const [world, setup] of Object.entries(WORLDS)) {
				const vm = mountIn(component, setup).vm
				resolved[world] = Object.fromEntries(keys.map((key) => [key, vm[key]]))
			}
			expect(resolved).toMatchSnapshot()
		})
	}
})

describe('NationalAnnounceWidget.announce', () => {
	const publication = { id: 'stored-id', title: 'Besluit' }

	/**
	 * Announce in the plain-context world with the given post outcome.
	 *
	 * @param {Function} post What axios.post does.
	 * @param {Function} [get] What axios.get does.
	 * @return {Promise<object>} What was shown and sent.
	 */
	async function announce(post, get = () => Promise.resolve({ data: publication })) {
		axios.get.mockImplementation(get)
		axios.post.mockImplementation(post)
		const wrapper = mountIn(NationalAnnounceWidget, WORLDS['plain context'])
		wrapper.vm.publicationType = { value: 'gemeenteblad' }
		wrapper.vm.effectiveDate = '2026-12-01'
		const pending = wrapper.vm.announce()
		const during = { announcing: wrapper.vm.announcing, error: wrapper.vm.error, results: wrapper.vm.results }
		await pending
		await flushPromises()
		return {
			during,
			gets: axios.get.mock.calls,
			posts: axios.post.mock.calls,
			announcing: wrapper.vm.announcing,
			error: wrapper.vm.error,
			results: wrapper.vm.results,
		}
	}

	const BODY = {
		delivered: [{ channel: 'national-publication-platform', delivery: { identifier: 'gmb-1' } }, { channel: 'local-channel' }],
		unreachable: [{ channel: 'x', reason: 'down' }],
		notices: [],
	}

	it('lists every channel after a full announcement', async () => {
		expect(await announce(() => Promise.resolve({ data: BODY }))).toMatchSnapshot()
	})

	it('lists every channel after a partial announcement answered with 502', async () => {
		expect(await announce(() => Promise.reject({ response: { data: { ...BODY, delivered: [] } } }))).toMatchSnapshot()
	})

	it('shows the error when the announcement failed otherwise', async () => {
		expect(await announce(() => Promise.reject({ response: { data: { message: 'no' } } }))).toMatchSnapshot()
		expect(await announce(() => Promise.reject(new Error('network')))).toMatchSnapshot()
	})

	it('shows the error when the publication cannot be read, and without a type sends none', async () => {
		expect(await announce(() => Promise.resolve({ data: BODY }), () => Promise.reject(new Error('404')))).toMatchSnapshot()
		axios.get.mockResolvedValue({ data: publication })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountIn(NationalAnnounceWidget, WORLDS.nothing)
		await wrapper.vm.announce()
		expect([axios.get.mock.calls, axios.post.mock.calls, wrapper.vm.results, wrapper.vm.error]).toMatchSnapshot()
	})
})

describe('PublicationVisibilityWidget.withdraw', () => {
	/**
	 * Withdraw with an answer and a post outcome.
	 *
	 * @param {object} answer What the dialog collected.
	 * @param {Function} post What axios.post does.
	 * @return {Promise<object>} What was shown and sent.
	 */
	async function withdraw(answer, post) {
		axios.post.mockImplementation(post)
		const wrapper = mountIn(PublicationVisibilityWidget, WORLDS['plain context'])
		await flushPromises()
		wrapper.vm.state = 'public'
		wrapper.vm.dialogOpen = true
		wrapper.vm.message = 'before'
		await wrapper.vm.withdraw(answer)
		return {
			posts: axios.post.mock.calls,
			busy: wrapper.vm.busy,
			dialogOpen: wrapper.vm.dialogOpen,
			state: wrapper.vm.state,
			messageType: wrapper.vm.messageType,
			message: wrapper.vm.message,
		}
	}

	it('withdraws one document', async () => {
		expect(await withdraw({ reason: 'privacy', fileId: 'f 1' }, () => Promise.resolve({ data: { outstandingChannels: ['x'] } }))).toMatchSnapshot()
	})

	it('withdraws the publication with and without outstanding channels', async () => {
		expect(await withdraw({ reason: 'r', fileId: '' }, () => Promise.resolve({ data: { outstandingChannels: ['a', 'b'] } }))).toMatchSnapshot()
		expect(await withdraw({ reason: 'r' }, () => Promise.resolve({ data: {} }))).toMatchSnapshot()
	})

	it('shows the error when the withdrawal fails', async () => {
		expect(await withdraw({ reason: 'r' }, () => Promise.reject({ response: { data: { message: 'refused' } } }))).toMatchSnapshot()
	})
})
