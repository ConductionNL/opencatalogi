/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the file size label that `UploadFiles.vue`
 * (bytesToSize) and `ViewObject.vue` (formatFileSize) show.
 */

import ViewObject from '../object/ViewObject.vue'
import UploadFiles from './UploadFiles.vue'

jest.mock('@toast-ui/editor/dist/toastui-editor.css', () => ({}))

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
	objectStore: { availableRegisters: [], availableSchemas: [] },
}))

describe.each([
	['UploadFiles bytesToSize', UploadFiles.methods.bytesToSize],
	['ViewObject formatFileSize', ViewObject.methods.formatFileSize],
])('%s', (_name, format) => {
	it.each([
		[0, 'n/a'],
		[1, '< 1 KB'],
		[1023, '< 1 KB'],
		[1024, '1.0 KB'],
		[1536, '1.5 KB'],
		[1048576, '1.0 MB'],
		[5 * 1024 ** 3, '5.0 GB'],
		[2 * 1024 ** 4, '2.0 TB'],
	])('labels %s bytes as %s', (bytes, label) => {
		expect(format(bytes)).toBe(label)
	})
})
