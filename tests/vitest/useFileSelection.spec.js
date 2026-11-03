/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterization tests for the file selection composable
 * (src/composables/UseFileSelection.js): which dropped files are queued,
 * which are rejected as duplicates, and when the callbacks fire.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const hooks = vi.hoisted(() => ({
	onDrop: null,
	onChange: null,
	resetFileDialog: null,
}))

const store = vi.hoisted(() => ({
	objectStore: {
		getRelatedData: null,
		getCollection: null,
		setActiveObject: null,
	},
}))

vi.mock('@vueuse/core', () => ({
	useDropZone: (_zone, options) => {
		hooks.onDrop = options.onDrop
		return { isOverDropZone: false }
	},
	useFileDialog: () => ({
		onChange: (handler) => {
			hooks.onChange = handler
		},
		open: () => {},
		reset: () => hooks.resetFileDialog(),
	}),
}))

vi.mock('../../src/store/store.js', () => store)

class FakeFileList {
	constructor(files) {
		files.forEach((file, index) => {
			this[index] = file
		})
		this.length = files.length
	}

	*[Symbol.iterator]() {
		for (let i = 0; i < this.length; i++) {
			yield this[i]
		}
	}
}
globalThis.FileList = FakeFileList

const { useFileSelection } =
	await import('../../src/composables/UseFileSelection.js')

/**
 * @param {string} name The file name.
 * @return {File} A small text file.
 */
function file(name) {
	return new File(['x'], name, { type: 'text/plain', lastModified: 5 })
}

/**
 * @param {object} options Options for the composable.
 * @return {object} The composable plus callback counters.
 */
function setup(options = {}) {
	const calls = { drop: 0, select: 0 }
	const api = useFileSelection({
		dropzone: null,
		allowMultiple: options.allowMultiple ?? true,
		allowedFileTypes: options.allowedFileTypes,
		onFileDrop: () => calls.drop++,
		onFileSelect: () => calls.select++,
	})
	return { api, calls }
}

/**
 * @param {object} api The composable.
 * @return {Array<string>|null} The queued file names.
 */
function queued(api) {
	return api.files.value === null ? null : api.files.value.map((f) => f.name)
}

beforeEach(() => {
	store.objectStore.getRelatedData = vi.fn(() => null)
	store.objectStore.getCollection = vi.fn(() => null)
	store.objectStore.setActiveObject = vi.fn()
	hooks.resetFileDialog = vi.fn()
})

describe('onDrop', () => {
	it('ignores a missing or empty drop', () => {
		const { api, calls } = setup()
		hooks.onDrop(null)
		hooks.onDrop([])
		expect(queued(api)).toBeNull()
		expect(api.rejectedDuplicates.value).toEqual({ names: [], seq: 0 })
		expect(calls).toEqual({ drop: 0, select: 0 })
	})

	it('keeps only the first file when multiple files are not allowed', () => {
		const { api, calls } = setup({ allowMultiple: false })
		hooks.onDrop([file('a.txt'), file('b.txt')])
		expect(queued(api)).toEqual(['a.txt'])
		expect(calls).toEqual({ drop: 1, select: 1 })
	})

	it('reads a FileList', () => {
		const { api } = setup()
		hooks.onDrop(new FakeFileList([file('a.txt'), file('b.txt')]))
		expect(queued(api)).toEqual(['a.txt', 'b.txt'])
	})

	it('wraps each file with the tags and a pending status', () => {
		const { api } = setup()
		api.setTags(['t1'])
		hooks.onDrop([file('a.txt')])
		const [wrapped] = api.files.value
		expect(wrapped).toBeInstanceOf(File)
		expect(wrapped.tags).toEqual(['t1'])
		expect(wrapped.status).toBe('pending')
		expect(wrapped.type).toBe('text/plain')
		expect(wrapped.lastModified).toBe(5)
	})

	it('rejects names already attached, queued or repeated in the drop', () => {
		store.objectStore.getRelatedData = vi.fn(() => ({
			results: [{ name: 'related.txt' }, { title: 'related-title.txt' }, {}],
		}))
		store.objectStore.getCollection = vi.fn(() => ({
			results: [{ name: 'col.txt', title: 'ignored.txt' }, null],
		}))
		const { api, calls } = setup()
		hooks.onDrop([file('q.txt')])
		hooks.onDrop([
			file('related.txt'),
			file('related-title.txt'),
			file('col.txt'),
			file('ignored.txt'),
			file('q.txt'),
			file('new.txt'),
			file('new.txt'),
		])
		expect(store.objectStore.getRelatedData).toHaveBeenCalledWith(
			'publication',
			'files',
		)
		expect(store.objectStore.getCollection).toHaveBeenCalledWith(
			'publicationAttachments',
		)
		expect(queued(api)).toEqual(['q.txt', 'ignored.txt', 'new.txt'])
		expect(api.rejectedDuplicates.value).toEqual({
			names: [
				'related.txt',
				'related-title.txt',
				'col.txt',
				'q.txt',
				'new.txt',
			],
			seq: 2,
		})
		expect(calls).toEqual({ drop: 2, select: 2 })
	})

	it('keeps the queue when every dropped file is a duplicate', () => {
		const { api, calls } = setup({ allowMultiple: false })
		hooks.onDrop([file('a.txt')])
		const before = api.files.value
		hooks.onDrop([file('a.txt')])
		expect(api.files.value).toBe(before)
		expect(api.rejectedDuplicates.value).toEqual({ names: ['a.txt'], seq: 2 })
		expect(calls).toEqual({ drop: 2, select: 2 })
	})

	it('replaces the queue when multiple files are not allowed', () => {
		const { api } = setup({ allowMultiple: false })
		hooks.onDrop([file('a.txt')])
		hooks.onDrop([file('b.txt')])
		expect(queued(api)).toEqual(['b.txt'])
	})

	it('reads the collection when the related data throws or is missing', () => {
		store.objectStore.getRelatedData = vi.fn(() => {
			throw new Error('boom')
		})
		store.objectStore.getCollection = vi.fn(() => ({
			results: [{ name: 'col.txt' }],
		}))
		const first = setup()
		hooks.onDrop([file('col.txt'), file('a.txt')])
		expect(queued(first.api)).toEqual(['a.txt'])

		store.objectStore.getRelatedData = undefined
		const second = setup()
		hooks.onDrop([file('col.txt'), file('b.txt')])
		expect(queued(second.api)).toEqual(['b.txt'])
	})

	it('keeps the names read before an attached entry throws', () => {
		const broken = {}
		Object.defineProperty(broken, 'name', {
			get() {
				throw new Error('boom')
			},
		})
		store.objectStore.getRelatedData = vi.fn(() => ({
			results: [{ name: 'early.txt' }, broken, { name: 'late.txt' }],
		}))
		store.objectStore.getCollection = vi.fn(() => {
			throw new Error('boom')
		})
		const { api } = setup()
		hooks.onDrop([file('early.txt'), file('late.txt')])
		expect(queued(api)).toEqual(['late.txt'])
		expect(api.rejectedDuplicates.value.names).toEqual(['early.txt'])
	})

	it('ignores results that are not a list', () => {
		store.objectStore.getRelatedData = vi.fn(() => ({ results: 'a.txt' }))
		store.objectStore.getCollection = vi.fn(() => ({ results: { 0: 'a' } }))
		const { api } = setup()
		hooks.onDrop([file('a.txt')])
		expect(queued(api)).toEqual(['a.txt'])
	})
})

describe('file dialog', () => {
	it('drops the chosen files and resets the input, even when the reset throws', () => {
		const { api } = setup()
		hooks.onChange([file('a.txt')])
		expect(hooks.resetFileDialog).toHaveBeenCalledTimes(1)
		hooks.resetFileDialog = vi.fn(() => {
			throw new Error('boom')
		})
		expect(() => hooks.onChange([file('b.txt')])).not.toThrow()
		expect(queued(api)).toEqual(['a.txt', 'b.txt'])
	})
})

describe('reset and setFiles', () => {
	it('removes one file by name, or empties the queue', () => {
		const { api } = setup()
		hooks.onDrop([file('a.txt'), file('b.txt')])
		api.reset('a.txt')
		expect(queued(api)).toEqual(['b.txt'])
		api.reset('b.txt')
		expect(queued(api)).toBeNull()
		hooks.onDrop([file('c.txt')])
		api.reset()
		expect(queued(api)).toBeNull()
	})

	it('sets the files and clears the active attachment', () => {
		const { api } = setup()
		const files = [file('a.txt')]
		api.setFiles(files)
		expect(api.files.value).toEqual(files)
		expect(store.objectStore.setActiveObject).toHaveBeenCalledWith(
			'attachment',
			null,
		)
	})
})
