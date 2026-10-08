/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `UploadFiles.vue` ("Add attachment" dialog).
 *
 * The component is mounted once by `Dialogs.vue` and stays mounted while the dialog opens and closes,
 * so every close path must clear the label selection.
 * The labels select always offers the translated "No label" option first, starts empty on every opening,
 * and "No label" uploads files without any `tags[]` field.
 * The specs mount it against the app's real pinia stores; only the network, `@vueuse/core` browser primitives and the NC components are stubbed.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { __fileDialog } from '@vueuse/core'
import axios from 'axios'
import { nextTick } from 'vue'
import UploadFiles from './UploadFiles.vue'
import { navigationStore, objectStore } from '../../store/store.js'

// `@vueuse/core` ships ESM only.
// The file dialog's change callback is captured so a spec can feed files in the way the browser picker would.
jest.mock('@vueuse/core', () => {
	const fileDialog = { onChange: null }
	return {
		__fileDialog: fileDialog,
		useDropZone: () => ({ isOverDropZone: { value: false } }),
		useFileDialog: () => ({
			onChange: (callback) => {
				fileDialog.onChange = callback
			},
			open: () => {},
			reset: () => {},
		}),
	}
})

jest.mock('axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))

const NO_LABEL = 'Geen label'
const AVAILABLE_TAGS = ['Besluit', 'Verslag']
const LABEL_OPTIONS = [NO_LABEL, ...AVAILABLE_TAGS]

const publicationA = {
	id: 'publication-a',
	'@self': { register: 'register-1', schema: 'schema-1' },
}
const publicationB = {
	id: 'publication-b',
	'@self': { register: 'register-1', schema: 'schema-1' },
}

/**
 * Translation stub matching the app's global `t` mixin signature. "No label" is translated so the specs prove the option is.
 *
 * @param {string} app App id.
 * @param {string} text Source string.
 * @param {object} [vars] Placeholder values.
 * @return {string}
 */
function t(app, text, vars = {}) {
	if (text === 'No label') return NO_LABEL
	return text.replace(/{(\w+)}/g, (match, key) =>
		key in vars ? String(vars[key]) : match,
	)
}

/**
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountUploadFiles() {
	return mount(UploadFiles, {
		global: { mocks: { t, n: t } },
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper Mounted dialog.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function labelSelect(wrapper) {
	return wrapper.findAllComponents({ name: 'NcSelect' })[0]
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper Mounted dialog.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function addFilesButton(wrapper) {
	return wrapper
		.findAll('button.nc-button-stub')
		.find((button) => button.text().includes('Add a file or files'))
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper Mounted dialog.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function doneButton(wrapper) {
	return wrapper
		.findAll('button.nc-button-stub')
		.find((button) => button.text() === 'Done')
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper Mounted dialog.
 * @return {boolean}
 */
function dropZoneDisabled(wrapper) {
	return (
		wrapper.find('.filesListDragDropNoticeWrapper--disabled').exists()
		&& addFilesButton(wrapper).attributes('disabled') !== undefined
	)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper Mounted dialog.
 * @param {string[]} labels Labels to select.
 * @return {Promise<void>}
 */
async function selectLabels(wrapper, labels) {
	labelSelect(wrapper).vm.$emit('update:modelValue', labels)
	await nextTick()
}

/**
 * @return {Promise<void>}
 */
async function openDialog() {
	navigationStore.setDialog('uploadFiles')
	await flushPromises()
}

/**
 * Pick one file through the file dialog and return the form data of the upload request it triggers.
 * The file selection outlives a mount and rejects names it has seen, so each caller passes its own name.
 *
 * @param {string} name File name.
 * @return {Promise<FormData>}
 */
async function uploadOneFile(name) {
	__fileDialog.onChange([new File(['content'], name)])
	await flushPromises()
	return axios.post.mock.calls.at(-1)[1]
}

describe('UploadFiles label selection', () => {
	let wrapper

	beforeEach(async () => {
		jest.spyOn(console, 'log').mockImplementation(() => {})
		jest.spyOn(console, 'info').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
		// The script block calls Nextcloud's global `t` (window.t).
		global.t = t
		global.fetch = jest.fn((url) =>
			Promise.resolve({
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve(
						String(url).endsWith('/api/tags')
							? AVAILABLE_TAGS
							: { results: [] },
					),
			}),
		)
		axios.post.mockReset()
		navigationStore.setDialog(null)
		await objectStore.setActiveObject('publication', publicationA)
		wrapper = mountUploadFiles()
		await flushPromises()
	})

	afterEach(() => {
		wrapper.unmount()
		navigationStore.setDialog(null)
		delete global.fetch
		delete global.t
		jest.restoreAllMocks()
	})

	describe('the "No label" option', () => {
		it('is offered translated and first, with nothing selected on open', async () => {
			await openDialog()

			expect(labelSelect(wrapper).props('options')).toEqual(LABEL_OPTIONS)
			expect(labelSelect(wrapper).props('modelValue')).toEqual([])
			expect(dropZoneDisabled(wrapper)).toBe(true)
		})

		it('is offered before the tags have loaded', async () => {
			wrapper.unmount()
			global.fetch = jest.fn(() => new Promise(() => {}))
			wrapper = mountUploadFiles()
			await openDialog()

			expect(labelSelect(wrapper).props('options')).toEqual([NO_LABEL])
			expect(labelSelect(wrapper).props('modelValue')).toEqual([])
		})

		it('is replaced when a label is picked', async () => {
			await openDialog()
			await selectLabels(wrapper, [NO_LABEL])
			await selectLabels(wrapper, [NO_LABEL, 'Besluit'])

			expect(labelSelect(wrapper).props('modelValue')).toEqual(['Besluit'])
		})

		it('replaces the labels when it is picked', async () => {
			await openDialog()
			await selectLabels(wrapper, ['Besluit'])
			await selectLabels(wrapper, ['Besluit', NO_LABEL])

			expect(labelSelect(wrapper).props('modelValue')).toEqual([NO_LABEL])
		})

		it('uploads without tags', async () => {
			axios.post.mockResolvedValue({ status: 200, data: [{ id: 'file-1' }] })
			await openDialog()
			await selectLabels(wrapper, [NO_LABEL])

			const form = await uploadOneFile('without-label.pdf')

			expect(form.getAll('files[]')).toHaveLength(1)
			expect(form.getAll('tags[]')).toEqual([])
		})

		it('leaves chosen labels to upload as tags when it is not selected', async () => {
			axios.post.mockResolvedValue({ status: 200, data: [{ id: 'file-1' }] })
			await openDialog()
			await selectLabels(wrapper, ['Besluit', 'Verslag'])

			const form = await uploadOneFile('with-labels.pdf')

			expect(form.getAll('tags[]')).toEqual(['Besluit,Verslag'])
		})
	})

	describe('reset on close', () => {
		/**
		 * @return {Promise<void>}
		 */
		async function openWithSelectionAndEditing() {
			await openDialog()
			await selectLabels(wrapper, ['Besluit'])
			// Through `$data`: VTU's `wrapper.vm` does not write through to `data()` on a component that also has <script setup>.
			wrapper.vm.$data.editingTags = 'besluit.pdf'
			wrapper.vm.$data.editedTags = ['Verslag']
			await nextTick()
		}

		/**
		 * @return {void}
		 */
		function expectLabelStateCleared() {
			const data = wrapper.vm.$data
			expect(data.labelOptions.value).toEqual([])
			expect(data.editingTags).toBeNull()
			expect(data.editedTags).toEqual([])
			expect(data.labelOptions.options).toEqual(LABEL_OPTIONS)
		}

		it('closeDialog clears the selection and editing state and keeps the options', async () => {
			await openWithSelectionAndEditing()

			wrapper.vm.closeDialog()
			await flushPromises()

			expectLabelStateCleared()
		})

		it('the modal close event (close button or ESC) clears the selection and editing state', async () => {
			await openWithSelectionAndEditing()

			wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
			await flushPromises()

			expect(navigationStore.dialog).toBeNull()
			expectLabelStateCleared()
		})

		it('an external dialog change clears the selection and editing state and keeps the options', async () => {
			await openWithSelectionAndEditing()

			navigationStore.setDialog(null)
			await flushPromises()

			expectLabelStateCleared()
		})

		it('leaves the drop zone disabled after a close until a label or "No label" is chosen', async () => {
			await openDialog()
			await selectLabels(wrapper, ['Besluit'])
			expect(dropZoneDisabled(wrapper)).toBe(false)

			wrapper.vm.closeDialog()
			await flushPromises()
			await openDialog()

			expect(dropZoneDisabled(wrapper)).toBe(true)

			await selectLabels(wrapper, [NO_LABEL])
			expect(dropZoneDisabled(wrapper)).toBe(false)
		})
	})

	describe('reopening the dialog', () => {
		it('starts empty after closing with Done and reopening for the same publication', async () => {
			await openDialog()
			await selectLabels(wrapper, ['Besluit', 'Verslag'])
			expect(labelSelect(wrapper).props('modelValue')).toEqual([
				'Besluit',
				'Verslag',
			])

			await doneButton(wrapper).trigger('click')
			await flushPromises()
			expect(wrapper.findComponent({ name: 'NcModal' }).exists()).toBe(false)

			await openDialog()

			expect(labelSelect(wrapper).props('modelValue')).toEqual([])
			expect(labelSelect(wrapper).props('options')).toEqual(LABEL_OPTIONS)
			expect(dropZoneDisabled(wrapper)).toBe(true)
		})

		it('starts empty after an external close and reopening for another publication', async () => {
			await openDialog()
			await selectLabels(wrapper, ['Verslag'])

			navigationStore.setDialog(null)
			await flushPromises()
			await objectStore.setActiveObject('publication', publicationB)
			await openDialog()

			expect(labelSelect(wrapper).props('modelValue')).toEqual([])
			expect(labelSelect(wrapper).props('options')).toEqual(LABEL_OPTIONS)
			expect(dropZoneDisabled(wrapper)).toBe(true)
		})

		it('starts empty after a successful upload with per-file label editing followed by close', async () => {
			axios.post.mockResolvedValue({ status: 200, data: [{ id: 'file-1' }] })

			await openDialog()
			await selectLabels(wrapper, ['Besluit'])
			__fileDialog.onChange([new File(['content'], 'besluit.pdf')])
			await flushPromises()

			expect(axios.post).toHaveBeenCalledTimes(1)
			expect(wrapper.vm.$data.success).toBe(true)
			expect(
				wrapper.findAll('.files-list__system-tag').map((tag) => tag.text()),
			).toEqual(['Besluit'])

			await wrapper.find('button.editTagsButton').trigger('click')
			expect(wrapper.vm.$data.editingTags).toBe('besluit.pdf')
			expect(wrapper.vm.$data.editedTags).toEqual(['Besluit'])

			await doneButton(wrapper).trigger('click')
			await flushPromises()
			await openDialog()

			expect(labelSelect(wrapper).props('modelValue')).toEqual([])
			expect(wrapper.vm.$data.editingTags).toBeNull()
			expect(wrapper.vm.$data.editedTags).toEqual([])
			expect(wrapper.find('table.files-table').exists()).toBe(false)
			expect(dropZoneDisabled(wrapper)).toBe(true)
		})

		it('starts without files or messages after an external close', async () => {
			axios.post.mockResolvedValue({ status: 200, data: [{ id: 'file-1' }] })

			await openDialog()
			await selectLabels(wrapper, ['Besluit'])
			__fileDialog.onChange([new File(['content'], 'besluit.pdf')])
			await flushPromises()
			__fileDialog.onChange([new File(['content'], 'besluit.pdf')])
			await flushPromises()

			expect(wrapper.find('table.files-table').exists()).toBe(true)
			expect(wrapper.vm.$data.duplicateWarning).toContain('besluit.pdf')
			wrapper.vm.$data.error = 'Upload failed'
			await nextTick()

			navigationStore.setDialog(null)
			await flushPromises()
			await openDialog()

			const data = wrapper.vm.$data
			expect(data.success).toBeNull()
			expect(data.error).toBeNull()
			expect(data.duplicateWarning).toBeNull()
			expect(wrapper.find('table.files-table').exists()).toBe(false)
			expect(wrapper.find('.nc-note-card-stub--warning').exists()).toBe(false)
			expect(wrapper.find('.nc-note-card-stub--error').exists()).toBe(false)
			expect(labelSelect(wrapper).props('modelValue')).toEqual([])
			expect(dropZoneDisabled(wrapper)).toBe(true)
		})
	})
})
