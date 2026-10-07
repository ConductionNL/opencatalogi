/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the labels select in `UploadFiles.vue`: the translated
 * "No label" option is always offered, is selected whenever the dialog opens,
 * and uploads files without any `tags[]` field.
 */

import { flushPromises, mount } from '@vue/test-utils'
import UploadFiles from './UploadFiles.vue'

const mockPost = jest.fn()
const mockSetTags = jest.fn()

const mockTranslate = (app, text) => (text === 'No label' ? 'Geen label' : text)

// vue3-jest compiles this mixed `<script>` + `<script setup>` SFC with two
// `var _vue` declarations, the second (`require('vue')`) shadowing the
// `@nextcloud/vue` one. The template therefore reads its Nc components off
// the `vue` module, which is where these stubs go.
jest.mock('vue', () => {
	const vue = jest.requireActual('vue')
	const slotStub = (name) => ({
		name,
		render() {
			return vue.h('div', this.$slots.default?.())
		},
	})
	return {
		...vue,
		NcModal: slotStub('NcModal'),
		NcButton: slotStub('NcButton'),
		NcNoteCard: slotStub('NcNoteCard'),
		NcCheckboxRadioSwitch: slotStub('NcCheckboxRadioSwitch'),
		NcLoadingIcon: slotStub('NcLoadingIcon'),
		NcSelect: {
			name: 'NcSelect',
			props: ['modelValue', 'options'],
			emits: ['update:modelValue'],
			render() {
				return vue.h('div', { class: 'nc-select-stub' })
			},
		},
	}
})

jest.mock('axios', () => ({
	__esModule: true,
	default: { post: (...args) => mockPost(...args) },
}))

jest.mock('../../store/store.js', () => {
	const { reactive: mockReactive } = require('vue')
	const navigationStore = mockReactive({
		dialog: null,
		setDialog(dialog) {
			navigationStore.dialog = dialog
		},
	})
	const publication = {
		id: 'pub-1',
		'@self': { register: 'reg-1', schema: 'schema-1' },
	}
	return {
		navigationStore,
		catalogStore: { fetchPublications: jest.fn() },
		objectStore: {
			setActiveObject: jest.fn(),
			getActiveObject: (type) => (type === 'publication' ? publication : {}),
			getCollection: () => null,
			setCollection: jest.fn(),
		},
	}
})

jest.mock('../../composables/UseFileSelection.js', () => {
	const { ref: mockRef } = require('vue')
	return {
		useFileSelection: () => ({
			openFileUpload: jest.fn(),
			files: mockRef(null),
			reset: jest.fn(),
			setTags: (...args) => mockSetTags(...args),
			rejectedDuplicates: mockRef({ names: [], seq: 0 }),
		}),
	}
})

jest.mock('../../entities/index.js', () => ({ Attachment: class {} }))

const { navigationStore } = require('../../store/store.js')

/**
 * Mount the dialog with its real template.
 *
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountDialog() {
	return mount(UploadFiles, { global: { mocks: { t: mockTranslate } } })
}

/**
 * The labels select; the only NcSelect rendered while no files are queued.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The mounted dialog.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function labelSelect(wrapper) {
	return wrapper.findComponent({ name: 'NcSelect' })
}

/**
 * Open or close the dialog the way ViewObject's "Add File" button and the
 * dialog's own close path do.
 *
 * @param {string|null} dialog The dialog to show, or null to close it.
 * @return {Promise<void>}
 */
async function setDialog(dialog) {
	navigationStore.setDialog(dialog)
	await flushPromises()
}

/**
 * Upload one file carrying the tags the dialog last handed to the file
 * selection, and return the request's form data.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The mounted dialog.
 * @return {Promise<FormData>}
 */
async function uploadWithCurrentTags(wrapper) {
	const file = new File(['x'], 'report.pdf')
	file.tags = mockSetTags.mock.calls.at(-1)[0]
	await wrapper.vm.createPublicationAttachment([file], jest.fn(), false)
	return mockPost.mock.calls.at(-1)[1]
}

describe('UploadFiles labels select', () => {
	let wrapper

	beforeAll(() => {
		global.t = mockTranslate
	})

	beforeEach(() => {
		mockPost.mockReset().mockResolvedValue({ status: 200, data: [] })
		mockSetTags.mockReset()
		global.fetch = jest.fn(async (url) => ({
			ok: true,
			json: async () =>
				url.endsWith('/api/tags') ? ['phone', 'campaign'] : {},
		}))
		navigationStore.dialog = null
		wrapper = mountDialog()
	})

	afterEach(() => {
		wrapper.unmount()
	})

	it('offers the translated "No label" option first and selects it on open', async () => {
		await setDialog('uploadFiles')

		expect(wrapper.vm.labelOptions.options).toEqual([
			'Geen label',
			'campaign',
			'phone',
		])
		expect(wrapper.vm.labelOptions.value).toEqual(['Geen label'])
	})

	it('binds "No label" to the rendered select and routes its updates', async () => {
		await setDialog('uploadFiles')

		const select = labelSelect(wrapper)
		expect(select.props('options')).toEqual(['Geen label', 'campaign', 'phone'])
		expect(select.props('modelValue')).toEqual(['Geen label'])

		select.vm.$emit('update:modelValue', ['Geen label', 'campaign'])
		await flushPromises()

		expect(labelSelect(wrapper).props('modelValue')).toEqual(['campaign'])
	})

	it('offers and selects "No label" before the tags have loaded', () => {
		wrapper.unmount()
		global.fetch = jest.fn(() => new Promise(() => {}))
		wrapper = mountDialog()

		expect(wrapper.vm.labelOptions.options).toEqual(['Geen label'])
		expect(wrapper.vm.labelOptions.value).toEqual(['Geen label'])
	})

	it('selects "No label" again when the dialog is reopened', async () => {
		await setDialog('uploadFiles')
		wrapper.vm.onLabelSelectionChange(['Geen label', 'campaign'])
		expect(wrapper.vm.labelOptions.value).toEqual(['campaign'])

		await setDialog(null)
		await setDialog('uploadFiles')

		expect(wrapper.vm.labelOptions.options[0]).toBe('Geen label')
		expect(wrapper.vm.labelOptions.value).toEqual(['Geen label'])
	})

	it('replaces the labels when "No label" is picked', async () => {
		await setDialog('uploadFiles')
		wrapper.vm.onLabelSelectionChange(['Geen label', 'campaign'])
		wrapper.vm.onLabelSelectionChange(['campaign', 'Geen label'])

		expect(wrapper.vm.labelOptions.value).toEqual(['Geen label'])
	})

	it('uploads without tags when "No label" is selected', async () => {
		await setDialog('uploadFiles')

		expect(mockSetTags).toHaveBeenLastCalledWith(null)

		const form = await uploadWithCurrentTags(wrapper)
		expect(form.getAll('files[]')).toHaveLength(1)
		expect(form.getAll('tags[]')).toEqual([])
	})

	it('uploads the chosen labels as tags', async () => {
		await setDialog('uploadFiles')
		wrapper.vm.onLabelSelectionChange(['Geen label', 'campaign'])
		wrapper.vm.onLabelSelectionChange(['campaign', 'phone'])
		await flushPromises()

		expect(mockSetTags).toHaveBeenLastCalledWith(['campaign', 'phone'])

		const form = await uploadWithCurrentTags(wrapper)
		expect(form.getAll('tags[]')).toEqual(['campaign,phone'])
	})
})
