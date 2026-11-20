/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the mass publish and depublish dialogs: which selected
 * publications they skip (already done, or a schema without the date fields),
 * the dates they save, the retroactive publish of depublished items, when the
 * submit is disabled, the date picker's guard against the past, and how
 * failures are reported. The clock is fixed at 2026-06-15 12:00 UTC.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import MassDepublishObjects from './MassDepublishObjects.vue'
import MassPublishObjects from './MassPublishObjects.vue'

jest.mock('../../store/store.js', () => ({
	catalogStore: { fetchPublications: jest.fn() },
	navigationStore: { setDialog: jest.fn() },
	objectStore: { selectedObjects: [], availableSchemas: [], saveObject: jest.fn() },
}))

jest.mock('../../services/publicationTransition.js', () => ({
	publishesThroughLifecycle: jest.fn(() => false),
	publishThroughLifecycle: jest.fn(),
}))

const { catalogStore, navigationStore, objectStore } = jest.requireMock('../../store/store.js')

/** Substitutes `{name}` placeholders, as the Nextcloud l10n does. */
const translate = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => String(vars[key] ?? match))

const DATED = { properties: { publicationDate: {}, depublicationDate: {} } }

/**
 * A selected publication.
 *
 * @param {string} id Its id.
 * @param {object} [dates] Its publication and depublication dates.
 * @param {object} [schema] Its schema; one with both date fields by default.
 * @return {object} The publication.
 */
function publication(id, dates = {}, schema = DATED) {
	return { id, title: id, publicationDate: null, depublicationDate: null, ...dates, '@self': { register: 'r1', schema } }
}

/**
 * Mount a dialog with its Nextcloud parts stubbed.
 *
 * @param {object} component The dialog.
 * @return {object} The wrapper.
 */
function open(component) {
	return shallowMount(component, {
		global: {
			mocks: { t: translate },
			stubs: {
				NcDialog: { props: ['name'], template: '<div><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' },
				NcNoteCard: { props: ['type'], template: '<div :class="\'note-\' + type"><slot /></div>' },
			},
		},
	})
}

/**
 * The publications saved, as `[id, publicationDate, depublicationDate, register]`.
 *
 * @return {Array<Array>} One row per save.
 */
function saved() {
	return objectStore.saveObject.mock.calls.map(([clone, target]) => [clone.id, clone.publicationDate, clone.depublicationDate, target.register])
}

beforeAll(() => {
	global.t = translate
})

beforeEach(() => {
	jest.useFakeTimers()
	jest.setSystemTime(new Date('2026-06-15T12:00:00Z'))
	catalogStore.fetchPublications.mockClear()
	navigationStore.setDialog.mockClear()
	objectStore.saveObject.mockReset().mockResolvedValue({})
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.useRealTimers()
	console.error.mockRestore()
})

describe('MassPublishObjects', () => {
	it('publishes today, clearing the depublication date, and skips the published and the unsupported', async () => {
		objectStore.selectedObjects = [
			publication('draft'),
			publication('published', { publicationDate: '2026-01-01' }),
			publication('scheduled', { publicationDate: '2026-12-01' }),
			publication('noDates', {}, { properties: { title: {} } }),
		]
		const wrapper = open(MassPublishObjects)
		expect(wrapper.vm.alreadyPublishedCount).toBe(1)
		expect(wrapper.vm.unsupportedCount).toBe(1)
		expect(wrapper.vm.alreadyPublishedWarning).toBe('1 of the selected publications is already published and will be skipped. Its publication date will not be changed.')
		expect(wrapper.vm.unsupportedWarning).toBe('1 of the selected publications has a schema that does not support publishing and will be skipped. Ask your IT manager for help.')
		expect(wrapper.find('h2').text()).toBe('Publish 4 publications')

		await wrapper.vm.publishObjects()
		await flushPromises()

		expect(saved()).toEqual([['draft', '2026-06-15', null, 'r1'], ['scheduled', '2026-06-15', null, 'r1']])
		expect(objectStore.saveObject.mock.calls[0][0]).not.toBe(objectStore.selectedObjects[0])
		expect(wrapper.vm.success).toBe(true)
		expect(wrapper.vm.successMessage).toBe('Publications successfully published')
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('publishes on the chosen date in the later mode, and needs that date', async () => {
		objectStore.selectedObjects = [publication('draft')]
		const wrapper = open(MassPublishObjects)
		wrapper.vm.$data.selectedMode = wrapper.vm.modeOptions[1]
		expect(wrapper.vm.mode).toBe('later')
		expect(wrapper.vm.submitDisabled).toBe(true)
		expect(wrapper.vm.infoText).toBe('The publication date will be set to the chosen date. Any existing depublication date will be removed.')

		wrapper.vm.handleDateInput(new Date(2026, 6, 4))
		expect(wrapper.vm.$data.publishDate).toBe('2026-07-04')
		expect(wrapper.vm.publishDateObj).toEqual(new Date(2026, 6, 4))
		expect(wrapper.vm.submitDisabled).toBe(false)

		await wrapper.vm.publishObjects()
		expect(saved()).toEqual([['draft', '2026-07-04', null, 'r1']])
		expect(wrapper.vm.successMessage).toBe('Publication successfully published')
	})

	it('refuses a past, invalid or empty date from the picker', () => {
		objectStore.selectedObjects = [publication('draft')]
		const wrapper = open(MassPublishObjects)
		wrapper.vm.handleDateInput('2026-07-04')
		expect(wrapper.vm.$data.publishDate).toBe('2026-07-04')

		for (const value of [new Date(2026, 0, 1), 'not a date', null]) {
			wrapper.vm.$data.publishDate = '2026-07-04'
			wrapper.vm.handleDateInput(value)
			expect(wrapper.vm.$data.publishDate).toBe(null)
		}
		expect(wrapper.vm.publishDateObj).toBe(null)
		expect(wrapper.vm.isDateBeforeMin(new Date(2026, 0, 1))).toBe(true)
		expect(wrapper.vm.isDateBeforeMin(new Date(2026, 11, 1))).toBe(false)
		expect(wrapper.vm.isDateBeforeMin('2026-01-01')).toBe(false)
	})

	it('offers a retroactive publish only when every selected item is depublished', async () => {
		objectStore.selectedObjects = [
			publication('gone', { publicationDate: '2026-01-01', depublicationDate: '2026-02-01' }),
			publication('draft'),
		]
		const mixed = open(MassPublishObjects)
		const option = mixed.vm.modeOptions[2]
		expect(option).toEqual({ id: 'retroactive', label: 'Publish retroactive', disabled: true, title: 'Only available when all selected items are depublished' })

		objectStore.selectedObjects = [publication('gone', { publicationDate: '2026-01-01', depublicationDate: '2026-02-01' })]
		const wrapper = open(MassPublishObjects)
		expect(wrapper.vm.modeOptions[2].disabled).toBe(false)
		wrapper.vm.$data.selectedMode = wrapper.vm.modeOptions[2]
		expect(wrapper.vm.infoText).toBe('The depublish date will be removed. The publish date will not change.')

		await wrapper.vm.publishObjects()
		expect(saved()).toEqual([['gone', '2026-01-01', null, 'r1']])
		expect(wrapper.vm.successMessage).toBe('Publication successfully published retroactive')
	})

	it('does nothing for a disabled mode', async () => {
		objectStore.selectedObjects = [publication('draft')]
		const wrapper = open(MassPublishObjects)
		wrapper.vm.$data.selectedMode = { id: 'retroactive', disabled: true }
		expect(wrapper.vm.submitDisabled).toBe(true)
		await wrapper.vm.publishObjects()
		expect(objectStore.saveObject).not.toHaveBeenCalled()
	})

	it('is disabled when every item would be skipped', () => {
		objectStore.selectedObjects = [publication('published', { publicationDate: '2026-01-01' })]
		expect(open(MassPublishObjects).vm.submitDisabled).toBe(true)
		objectStore.selectedObjects = [publication('noDates', {}, { properties: {} })]
		expect(open(MassPublishObjects).vm.submitDisabled).toBe(true)
		objectStore.selectedObjects = []
		expect(open(MassPublishObjects).vm.submitDisabled).toBe(true)
	})

	it('names the failures and keeps the dialog open', async () => {
		objectStore.selectedObjects = [publication('a'), publication('b'), publication('c')]
		objectStore.saveObject.mockResolvedValueOnce({}).mockRejectedValue(new Error('refused'))
		const wrapper = open(MassPublishObjects)
		await wrapper.vm.publishObjects()
		expect(wrapper.vm.error).toBe('Failed to publish 2 object(s)')
		expect(wrapper.vm.success).toBe(true)
		expect(wrapper.vm.loading).toBe(false)
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		jest.runAllTimers()
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
	})

	it('counts several skipped items in the warnings', () => {
		objectStore.selectedObjects = [
			publication('p1', { publicationDate: '2026-01-01' }),
			publication('p2', { publicationDate: '2026-01-01', depublicationDate: '2025-12-01' }),
			publication('n1', {}, { properties: {} }),
			publication('n2', {}, { properties: {} }),
		]
		const wrapper = open(MassPublishObjects)
		expect(wrapper.vm.alreadyPublishedWarning).toBe('2 of the selected publications are already published and will be skipped. Their publication dates will not be changed.')
		expect(wrapper.vm.unsupportedWarning).toBe('2 of the selected publications have schemas that do not support publishing and will be skipped. Ask your IT manager for help.')
		expect(wrapper.vm.isObjectUnsupported(objectStore.selectedObjects[2])).toBe(true)
		expect(wrapper.vm.unsupportedReason()).toBe('This schema does not support publishing. Ask your IT manager for help.')
	})
})

describe('MassDepublishObjects', () => {
	it('depublishes today, keeping the publication date, and skips the depublished and the unsupported', async () => {
		objectStore.selectedObjects = [
			publication('live', { publicationDate: '2026-01-01' }),
			publication('gone', { publicationDate: '2026-01-01', depublicationDate: '2026-02-01' }),
			publication('ending', { publicationDate: '2026-01-01', depublicationDate: '2026-12-01' }),
			publication('noDates', {}, { properties: {} }),
		]
		const wrapper = open(MassDepublishObjects)
		expect(wrapper.vm.alreadyDepublishedCount).toBe(1)
		expect(wrapper.vm.alreadyDepublishedWarning).toBe('1 of the selected publications is already depublished and will be skipped. Its depublication date will not be changed.')
		expect(wrapper.vm.unsupportedWarning).toBe('1 of the selected publications has a schema that does not support depublishing and will be skipped. Ask your IT manager for help.')
		expect(wrapper.find('h2').text()).toBe('Depublish 4 publications')
		expect(wrapper.vm.infoText).toBe("Publications will be depublished with today's date. The publication date will remain unchanged.")

		await wrapper.vm.depublishObjects()
		await flushPromises()

		expect(saved()).toEqual([['live', '2026-01-01', '2026-06-15', 'r1'], ['ending', '2026-01-01', '2026-06-15', 'r1']])
		expect(wrapper.vm.success).toBe(true)
		expect(wrapper.vm.successMessage).toBe('Publications successfully depublished')
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		jest.advanceTimersByTime(2000)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('depublishes on the chosen date in the later mode, and refuses a past one', async () => {
		objectStore.selectedObjects = [publication('live', { publicationDate: '2026-01-01' })]
		const wrapper = open(MassDepublishObjects)
		expect(wrapper.find('h2').text()).toBe('Depublish publication')
		wrapper.vm.$data.selectedMode = wrapper.vm.modeOptions[1]
		expect(wrapper.vm.submitDisabled).toBe(true)
		expect(wrapper.vm.infoText).toBe('The depublication date will be set to the chosen date. The publication date will remain unchanged.')

		wrapper.vm.handleDateInput(new Date(2026, 0, 1))
		expect(wrapper.vm.$data.depublishDate).toBe(null)
		wrapper.vm.handleDateInput('not a date')
		expect(wrapper.vm.$data.depublishDate).toBe(null)
		wrapper.vm.handleDateInput(new Date(2026, 8, 30))
		expect(wrapper.vm.depublishDateObj).toEqual(new Date(2026, 8, 30))
		expect(wrapper.vm.submitDisabled).toBe(false)

		await wrapper.vm.depublishObjects()
		expect(saved()).toEqual([['live', '2026-01-01', '2026-09-30', 'r1']])
		expect(wrapper.vm.successMessage).toBe('Publication successfully depublished')

		wrapper.vm.handleDateInput(null)
		expect(wrapper.vm.$data.depublishDate).toBe(null)
		expect(wrapper.vm.isDateBeforeMin(new Date(2025, 0, 1))).toBe(true)
		expect(wrapper.vm.isDateBeforeMin(new Date('x'))).toBe(false)
	})

	it('is disabled when every item would be skipped, and counts several in the warnings', () => {
		objectStore.selectedObjects = [
			publication('g1', { depublicationDate: '2026-02-01' }),
			publication('g2', { publicationDate: '2026-01-01', depublicationDate: '2026-01-01' }),
		]
		const wrapper = open(MassDepublishObjects)
		expect(wrapper.vm.submitDisabled).toBe(true)
		expect(wrapper.vm.alreadyDepublishedWarning).toBe('2 of the selected publications are already depublished and will be skipped. Their depublication dates will not be changed.')

		objectStore.selectedObjects = [publication('n1', {}, { properties: {} }), publication('n2', {}, { properties: {} })]
		const unsupported = open(MassDepublishObjects)
		expect(unsupported.vm.submitDisabled).toBe(true)
		expect(unsupported.vm.unsupportedWarning).toBe('2 of the selected publications have schemas that do not support depublishing and will be skipped. Ask your IT manager for help.')
		expect(unsupported.vm.unsupportedReason()).toBe('This schema does not support depublishing. Ask your IT manager for help.')
	})

	it('names the failures and keeps the dialog open', async () => {
		objectStore.selectedObjects = [publication('a', { publicationDate: '2026-01-01' })]
		objectStore.saveObject.mockRejectedValue(new Error('refused'))
		const wrapper = open(MassDepublishObjects)
		await wrapper.vm.depublishObjects()
		expect(wrapper.vm.error).toBe('Failed to depublish 1 object(s)')
		expect(wrapper.vm.success).toBe(null)
		expect(catalogStore.fetchPublications).not.toHaveBeenCalled()
		wrapper.vm.handleDialogClose(true)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		wrapper.vm.handleDialogClose(false)
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})
})
