/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for src/composables/UseFileSelection.js: what a drop or a file
 * pick leaves in the file list, which names it rejects as duplicates (queued,
 * related publication files, stored attachments), and which callbacks it
 * runs, for each option set and store shape. Offline, no DOM. Set
 * RECORD_GOLDEN=1 to rewrite the fixture.
 */
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterAll, describe, expect, it, vi } from 'vitest'

const hooks = vi.hoisted(() => ({ store: {}, onDrop: null, onChange: null, dialogOptions: null, dropOptions: null, resets: 0 }))

vi.mock('@vueuse/core', () => ({
	useDropZone: (_zone, options) => {
		hooks.dropOptions = options
		hooks.onDrop = options.onDrop
		return { isOverDropZone: false }
	},
	useFileDialog: (options) => {
		hooks.dialogOptions = options
		return {
			onChange: (callback) => { hooks.onChange = callback },
			open: () => {},
			reset: () => { hooks.resets++ },
		}
	},
}))

vi.mock('../../src/store/store.js', () => ({
	objectStore: new Proxy({}, {
		get: (_target, key) => hooks.store[key],
	}),
}))

const { useFileSelection } = await import('../../src/composables/UseFileSelection.js')

const FIXTURE = resolve(process.cwd(), 'tests/vitest/fixtures/use-file-selection-golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = {}
const expected = RECORD ? {} : JSON.parse(readFileSync(FIXTURE, 'utf8'))

afterAll(() => {
	if (RECORD) {
		writeFileSync(FIXTURE, JSON.stringify(recorded, null, '\t') + '\n')
	}
})

/** A FileList look-alike, so the composable's FileList branch runs in node. */
class FakeFileList {
	constructor(files) {
		files.forEach((file, i) => { this[i] = file })
		this.length = files.length
	}

	* [Symbol.iterator]() {
		for (let i = 0; i < this.length; i++) yield this[i]
	}
}
globalThis.FileList = FakeFileList

const file = (name) => new File(['x'], name, { type: 'text/plain', lastModified: 1 })

/**
 * The store shape of a case, recording every read.
 *
 * @param {object} spec the shape
 * @param {Array} reads receives the reads
 * @return {object} the store
 */
function storeOf(spec, reads) {
	const store = { setActiveObject: (...args) => reads.push(['setActiveObject', ...args]) }
	if (spec.related !== 'missing') {
		store.getRelatedData = (...args) => {
			reads.push(['getRelatedData', ...args])
			if (spec.related === 'throw') throw new Error('related')
			return spec.related
		}
	}
	if (spec.collection !== 'missing') {
		store.getCollection = (...args) => {
			reads.push(['getCollection', ...args])
			if (spec.collection === 'throw') throw new Error('collection')
			return spec.collection
		}
	}
	return store
}

const describeFile = (f) => ({ name: f.name, type: f.type, tags: f.tags, status: f.status, isFile: f instanceof File })
const describeList = (list) => (Array.isArray(list) ? list.map(describeFile) : list)

const withNames = { results: [{ name: 'a.txt' }, { title: 'b.txt' }, { name: '', title: 'c.txt' }, {}, null] }
const STORES = {
	empty: { related: undefined, collection: undefined },
	related: { related: withNames, collection: { results: 'x' } },
	collection: { related: { results: null }, collection: { results: [{ title: 'd.txt' }, { name: 'a.txt', title: 'z' }] } },
	throwing: { related: 'throw', collection: 'throw' },
	missing: { related: 'missing', collection: 'missing' },
}

const DROPS = {
	none: [null],
	emptyArray: [[]],
	single: [['new.txt']],
	several: [['new.txt', 'a.txt', 'new.txt', 'd.txt', 'c.txt', 'other.txt']],
	fileList: [{ list: ['one.txt', 'two.txt'] }],
	twoDrops: [['one.txt'], ['two.txt', 'one.txt']],
	allDuplicates: [['a.txt', 'b.txt']],
	dropThenAllDuplicates: [['one.txt'], ['one.txt']],
}

const OPTIONS = {
	multiple: { allowMultiple: true, allowedFileTypes: ['.pdf', '.txt'] },
	single: { allowMultiple: false, allowedFileTypes: '.pdf' },
	noTypes: { allowMultiple: true },
}

/**
 * Runs one case: mounts the composable, applies the drops, records the state.
 *
 * @param {string} optionName key into OPTIONS
 * @param {string} storeName key into STORES
 * @param {string} dropName key into DROPS
 * @param {boolean} viaChange whether the drops arrive through the file dialog
 * @return {object} the recorded state
 */
function runCase(optionName, storeName, dropName, viaChange) {
	const reads = []
	const calls = { drop: 0, select: 0 }
	hooks.store = storeOf(STORES[storeName], reads)
	hooks.resets = 0
	const selection = useFileSelection({
		dropzone: 'zone',
		...OPTIONS[optionName],
		onFileDrop: () => calls.drop++,
		onFileSelect: () => calls.select++,
	})
	selection.setTags(['tag-a'])
	const steps = []
	for (const drop of DROPS[dropName]) {
		let files = drop
		if (Array.isArray(drop)) files = drop.map(file)
		else if (drop && drop.list) files = new FakeFileList(drop.list.map(file))
		if (viaChange) hooks.onChange(files)
		else hooks.onDrop(files)
		steps.push({ files: describeList(selection.files.value), rejected: selection.rejectedDuplicates.value })
	}
	return {
		steps,
		calls,
		reads,
		resets: hooks.resets,
		accept: hooks.dialogOptions.accept,
		dialog: { multiple: hooks.dialogOptions.multiple, reset: hooks.dialogOptions.reset },
		dropDataTypes: hooks.dropOptions.dataTypes,
	}
}

/**
 * Compares (or records) one case.
 *
 * @param {string} key the case key
 * @param {object} actual what the case produced
 */
function check(key, actual) {
	const value = JSON.parse(JSON.stringify(actual))
	recorded[key] = value
	if (!RECORD) {
		expect(value).toEqual(expected[key])
	}
}

describe('useFileSelection (golden)', () => {
	for (const optionName of Object.keys(OPTIONS)) {
		for (const storeName of Object.keys(STORES)) {
			for (const dropName of Object.keys(DROPS)) {
				it(`${optionName} / ${storeName} / ${dropName}`, () => {
					check(`${optionName}/${storeName}/${dropName}`, runCase(optionName, storeName, dropName, false))
				})
			}
		}
		it(`${optionName} / via the file dialog`, () => {
			check(`${optionName}/change`, runCase(optionName, 'related', 'twoDrops', true))
		})
	}

	it('runs without callbacks', () => {
		hooks.store = storeOf(STORES.empty, [])
		const selection = useFileSelection({ dropzone: 'zone', allowMultiple: true })
		hooks.onDrop([file('a.txt')])
		check('no callbacks', describeList(selection.files.value))
	})

	it('resets one file, the last file and everything, and sets files', () => {
		const reads = []
		hooks.store = storeOf(STORES.empty, reads)
		const selection = useFileSelection({ dropzone: 'zone', allowMultiple: true })
		hooks.onDrop([file('a.txt'), file('b.txt')])
		const out = []
		selection.reset('a.txt')
		out.push(describeList(selection.files.value))
		selection.reset('b.txt')
		out.push(describeList(selection.files.value))
		hooks.onDrop([file('c.txt')])
		selection.reset()
		out.push(describeList(selection.files.value))
		selection.setFiles(['raw'])
		out.push(selection.files.value)
		check('reset and set', { out, reads })
	})
})
