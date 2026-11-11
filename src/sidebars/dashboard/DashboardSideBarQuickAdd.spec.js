/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the quick publication form of `DashboardSideBar.vue`: the
 * publication types offered for the chosen catalog, and the attachment upload
 * that follows a new publication.
 */

import axios from 'axios'
import DashboardSideBar from './DashboardSideBar.vue'
import { objectStore } from '../../store/store.js'

jest.mock('axios', () => ({ __esModule: true, default: { post: jest.fn() } }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('../../composables/UseFileSelection.js', () => {
	const mockFiles = { value: [{ name: 'a.pdf' }] }
	return {
		mockFiles,
		mockReset: jest.fn(),
		useFileSelection() {
			return { files: mockFiles, reset: jest.requireMock('../../composables/UseFileSelection.js').mockReset }
		},
	}
})
jest.mock('../../store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		getCollection: jest.fn(),
		fetchCollection: jest.fn(),
		updateObject: jest.fn(),
		setActiveObject: jest.fn(),
	},
}))

const { mockFiles, mockReset } = jest.requireMock('../../composables/UseFileSelection.js')

const types = [
	{ id: 1, source: 's1', title: 'One' },
	{ id: 2, source: 's2', title: 'Two' },
	{ id: 3, source: 's3', title: 'Three' },
]

/**
 * Evaluate filteredPublicationTypeOptions on a form state.
 *
 * @param {object} state The catalogi select and the publication type list.
 * @return {object} The options object.
 */
function options(state) {
	return DashboardSideBar.computed.filteredPublicationTypeOptions.call({ ...DashboardSideBar.methods, ...state })
}

describe('DashboardSideBar publication type options', () => {
	beforeEach(() => {
		objectStore.getCollection.mockReturnValue({
			results: [
				{ id: 10, publicationTypes: [1, '3'] },
				{ id: 11, publicationTypes: [2] },
			],
		})
	})

	it.each([
		['no catalog options', { catalogi: { options: [], value: { id: 10 } }, publicationTypeList: types }],
		['no chosen catalog', { catalogi: { options: [{}], value: null }, publicationTypeList: types }],
		['no publication types', { catalogi: { options: [{}], value: { id: 10 } }, publicationTypeList: [] }],
	])('is empty with %s', (label, state) => {
		expect(options(state)).toEqual({})
	})

	it('offers the chosen catalog publication types, matched as text', () => {
		expect(options({ catalogi: { options: [{}], value: { id: '10' } }, publicationTypeList: types })).toEqual({
			options: [
				{ id: 1, source: 's1', label: 'One' },
				{ id: 3, source: 's3', label: 'Three' },
			],
		})
	})

	it('throws when the chosen catalog is not in the store', () => {
		expect(() => options({ catalogi: { options: [{}], value: { id: 99 } }, publicationTypeList: types })).toThrow(TypeError)
	})
})

describe('DashboardSideBar addAttachment', () => {
	const flush = async () => {
		for (let i = 0; i < 8; i++) {
			await Promise.resolve()
		}
	}

	beforeEach(() => {
		jest.useFakeTimers()
		jest.clearAllMocks()
		mockFiles.value = [{ name: 'a.pdf' }]
	})

	afterEach(() => {
		jest.useRealTimers()
	})

	it('uploads the file, links it to the publication and refreshes', async () => {
		const linked = { ok: true, json: () => Promise.resolve({ id: 'p1', linked: true }) }
		axios.post.mockResolvedValue({ status: 201, data: { id: 'att1' } })
		objectStore.updateObject.mockResolvedValue(linked)
		const state = { ...DashboardSideBar.methods, loading: false, success: null }
		const publication = { id: 'p1', title: 'Pub', attachments: ['att0'] }

		DashboardSideBar.methods.addAttachment.call(state, publication)
		expect(state.loading).toBe(true)
		expect(state.errorMessage).toBe(false)
		await flush()

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/opencatalogi/api/attachments',
			{ published: null, summary: '', license: '', _file: { name: 'a.pdf' } },
			{ headers: { 'Content-Type': 'multipart/form-data', 'Publication-Id': 'p1', 'Publication-Title': 'Pub' } },
		)
		expect(mockReset).toHaveBeenCalledTimes(1)
		expect(objectStore.fetchCollection.mock.calls).toEqual([['attachment'], ['publication']])
		expect(objectStore.updateObject).toHaveBeenCalledWith('publication', 'p1', { ...publication, attachments: ['att0', 'att1'] })
		expect(objectStore.setActiveObject.mock.calls).toEqual([
			['attachment', { status: 201, data: { id: 'att1' } }],
			['publication', { id: 'p1', linked: true }],
		])
		expect(state).toMatchObject({ success: true, loading: false })
		jest.advanceTimersByTime(2000)
		expect(state.success).toBeNull()
	})

	it('keeps the error of a failed link', async () => {
		axios.post.mockResolvedValue({ status: 201, data: { id: 'att1' } })
		objectStore.updateObject.mockRejectedValue('link failed')
		const state = { ...DashboardSideBar.methods, loading: false }
		DashboardSideBar.methods.addAttachment.call(state, { id: 'p1', title: 'Pub', attachments: [] })
		await flush()

		expect(state).toMatchObject({ error: 'link failed', loading: false, success: 201 })
	})

	it.each([
		[{ response: { data: { error: 'Too big' } } }, 'Too big'],
		[{ message: 'down' }, { message: 'down' }],
	])('shows the upload error', async (err, expected) => {
		axios.post.mockRejectedValue(err)
		const state = { ...DashboardSideBar.methods, loading: false }
		DashboardSideBar.methods.addAttachment.call(state, { id: 'p1', title: 'Pub', attachments: [] })
		await flush()

		expect(state.error).toEqual(expected)
		expect(state.loading).toBe(false)
		expect(objectStore.updateObject).not.toHaveBeenCalled()
	})

	it('sends an empty file field without a selection', async () => {
		mockFiles.value = null
		axios.post.mockReturnValue(new Promise(() => {}))
		DashboardSideBar.methods.addAttachment.call({ ...DashboardSideBar.methods }, { id: 'p1', title: 'Pub', attachments: [] })

		expect(axios.post.mock.calls[0][1]._file).toBe('')
	})
})
