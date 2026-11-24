/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the mass publish dialog and the fields required to
 * publish (publication-required-at-publish REQ-PRP-003): an incomplete
 * selection is named with its missing fields and skipped, the button says how
 * many are published, and OpenRegister's 422 is shown on the publication.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import MassObjectActionDialog from './MassObjectActionDialog.vue'
import MassPublishObjects from './MassPublishObjects.vue'

jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: { setDialog: jest.fn() },
	objectStore: {
		selectedObjects: [],
		availableSchemas: [],
		saveObject: jest.fn(),
	},
}))

jest.mock('../../services/publicationTransition.js', () => ({
	publishesThroughLifecycle: jest.fn(() => true),
	publishThroughLifecycle: jest.fn(),
}))

const { objectStore } = jest.requireMock('../../store/store.js')
const { publishThroughLifecycle } = jest.requireMock(
	'../../services/publicationTransition.js',
)

function translate(app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (match, key) => String(vars[key] ?? match))
}

const SCHEMA = {
	properties: { publicationDate: {}, depublicationDate: {} },
	'x-openregister-lifecycle': {
		states: {
			published: {
				fields: {
					required: [
						{ fields: ['summary', 'description', 'organization'] },
					],
				},
			},
		},
	},
}

/**
 * A selected draft publication.
 *
 * @param {string} title Its title, also its id.
 * @param {object} fields Its other fields.
 * @param {object} [schema] Its schema.
 * @return {object} The publication.
 */
function publication(title, fields, schema = SCHEMA) {
	return {
		id: title,
		title,
		status: 'draft',
		publicationDate: null,
		depublicationDate: null,
		...fields,
		'@self': { id: title, register: 'r1', schema },
	}
}

const COMPLETE = {
	summary: 'Agenda',
	description: 'Raadsvergadering',
	organization: 'org-1',
}

/**
 * Mount the dialog.
 *
 * @return {object} The wrapper.
 */
function open() {
	return shallowMount(MassPublishObjects, {
		global: {
			mocks: { t: translate },
			stubs: {
				MassObjectActionDialog: {
					props: ['confirmLabel', 'confirmDisabled', 'error'],
					template: '<div><slot /><slot name="notes" /></div>',
				},
				NcNoteCard: {
					props: ['type'],
					template: '<div :class="\'note-\' + type"><slot /></div>',
				},
			},
		},
	})
}

beforeAll(() => {
	global.t = translate
})

beforeEach(() => {
	publishThroughLifecycle.mockReset()
	publishThroughLifecycle.mockResolvedValue({})
})

describe('MassPublishObjects and the fields required to publish', () => {
	it('names the incomplete one with "summary" and publishes 1 of 2', async () => {
		objectStore.selectedObjects = [
			publication('Council papers for 15 October', {
				...COMPLETE,
				summary: '',
			}),
			publication('Budget 2027', COMPLETE),
		]
		const wrapper = open()
		const dialog = wrapper.findComponent(MassObjectActionDialog)

		expect(wrapper.find('[data-testid="publish-incomplete"]').text()).toContain(
			'Council papers for 15 October: summary',
		)
		expect(dialog.props('confirmLabel')).toBe('Publish 1 of 2 publications')
		expect(dialog.props('confirmDisabled')).toBe(false)

		await wrapper.vm.publishObjects()
		await flushPromises()

		expect(publishThroughLifecycle).toHaveBeenCalledTimes(1)
		expect(publishThroughLifecycle.mock.calls[0][0].title).toBe('Budget 2027')
	})

	it('keeps the plain label and lists nothing when the schema declares no rule', () => {
		const plain = { properties: SCHEMA.properties }
		objectStore.selectedObjects = [publication('Only a title', {}, plain)]
		const wrapper = open()

		expect(wrapper.find('[data-testid="publish-incomplete"]').exists()).toBe(
			false,
		)
		expect(
			wrapper.findComponent(MassObjectActionDialog).props('confirmLabel'),
		).toBe('Publish')
	})

	it('disables the publish when every selection is incomplete', () => {
		objectStore.selectedObjects = [publication('Leeg', {})]
		const wrapper = open()

		expect(
			wrapper.findComponent(MassObjectActionDialog).props('confirmDisabled'),
		).toBe(true)
	})

	it('shows the server 422 on the publication it names', async () => {
		objectStore.selectedObjects = [publication('Budget 2027', COMPLETE)]
		publishThroughLifecycle.mockRejectedValue({
			response: {
				status: 422,
				data: { message: 'organization is required in state published' },
			},
		})
		const wrapper = open()

		await wrapper.vm.publishObjects()
		await flushPromises()

		expect(wrapper.vm.error).toBe(
			'Budget 2027: organization is required in state published',
		)
	})
})
