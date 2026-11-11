/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `UploadFiles.vue`'s createPublicationAttachment:
 * its argument checks, the multipart form it sends (files, labels, share
 * flag), the endpoint and the logs. The method runs against a plain
 * `this`, so no component is mounted.
 */

import axios from 'axios'
import { ref } from 'vue'
import UploadFiles from './UploadFiles.vue'

jest.mock('axios', () => ({ __esModule: true, default: { post: jest.fn() } }))

jest.mock('../../composables/UseFileSelection.js', () => ({
	useFileSelection: () => ({
		openFileUpload: () => {},
		files: { value: [] },
		reset: () => {},
		setTags: () => {},
		rejectedDuplicates: { value: [] },
	}),
}))

jest.mock('../../store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: { getActiveObject: () => ({ id: 'p1', '@self': { register: 7, schema: 9 } }) },
}))

/**
 * A plain `this` with every method of the dialog.
 *
 * @return {object} The context
 */
function vm() {
	const ctx = {}
	for (const [name, fn] of Object.entries(UploadFiles.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

/**
 * The entries of a sent form.
 *
 * @param {FormData} form - The form
 * @return {Array} The entries, files by name
 */
function entries(form) {
	return [...form.entries()].map(([key, value]) => [key, typeof value === 'string' ? value : value.name])
}

beforeEach(() => {
	axios.post.mockReset()
	jest.spyOn(console, 'info').mockImplementation(() => {})
	jest.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('UploadFiles createPublicationAttachment', () => {
	const a = new File(['a'], 'a.txt')
	a.tags = ['x', 'y']
	const b = new File(['b'], 'b.txt')
	b.tags = []
	const c = new File(['c'], 'c.txt')

	it('posts the files with their labels and the share flag', async () => {
		const response = { data: [{ id: 'f1' }] }
		axios.post.mockResolvedValue(response)
		const result = await vm().createPublicationAttachment([a, b, c], () => {}, true)
		expect(result).toBe(response)
		const [url, form, options] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/7/9/p1/filesMultipart')
		expect(entries(form)).toEqual([
			['files[]', 'a.txt'],
			['tags[]', 'x,y'],
			['files[]', 'b.txt'],
			['files[]', 'c.txt'],
			['share', 'true'],
		])
		expect(options).toEqual({ headers: { 'Content-Type': 'multipart/form-data' } })
		expect(console.info).toHaveBeenCalledWith('Importing files:', response.data)
	})

	it('unwraps a ref and defaults the share flag to false', async () => {
		axios.post.mockResolvedValue({ data: [] })
		await vm().createPublicationAttachment(ref([c]), () => {})
		expect(entries(axios.post.mock.calls[0][1])).toEqual([['files[]', 'c.txt'], ['share', 'false']])
	})

	it.each([
		[null, () => {}, 'No files to import'],
		[[c], null, 'No reset function to call'],
		[{ not: 'array' }, () => {}, 'Files is not an array'],
		[ref('x'), () => {}, 'Files is not an array'],
	])('refuses %j', async (files, reset, message) => {
		await expect(vm().createPublicationAttachment(files, reset)).rejects.toThrow(message)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('logs and rethrows a failed upload', async () => {
		const failure = new Error('too big')
		axios.post.mockRejectedValue(failure)
		await expect(vm().createPublicationAttachment([c], () => {})).rejects.toBe(failure)
		expect(console.error).toHaveBeenCalledWith('Error importing files:', failure)
	})
})
