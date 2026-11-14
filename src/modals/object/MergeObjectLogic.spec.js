/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the merge logic of `MergeObject.vue`: the
 * default value and dropdown selection per property, the merge options of a
 * property, and the merge request with custom and option values resolved.
 * Each method runs against a plain `this`, so no component is mounted.
 */

import axios from '@nextcloud/axios'
import MergeObject from './MergeObject.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) => '/nc' + path.replace(/{(\w+)}/g, (_, k) => params[k]),
}))

/**
 * A plain `this` with the given state and every method of the dialog.
 *
 * @param {object} state - The dialog state
 * @return {object} The context
 */
function vm(state) {
	const ctx = { ...state }
	for (const [name, fn] of Object.entries(MergeObject.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

let logs

beforeEach(() => {
	logs = []
	jest.spyOn(console, 'log').mockImplementation((...args) => logs.push(JSON.parse(JSON.stringify(args))))
	jest.spyOn(console, 'error').mockImplementation(() => {})
	axios.post.mockReset()
})

afterEach(() => {
	jest.restoreAllMocks()
})

describe('MergeObject initializeMergeData', () => {
	it('defaults each property to the target, else the source, else custom', () => {
		const ctx = vm({
			mergeableProperties: ['id', 'title', 'summary', 'tags', 'empty', 'zero', 'obj'],
			selectedTargetObject: { id: 't', title: 'T', summary: '', tags: null, zero: 0, obj: { a: 1 } },
			sourceObject: { id: 's', title: 'S', summary: 'S sum', tags: ['x'], empty: '' },
		})
		ctx.initializeMergeData()
		expect(ctx.mergedData).toEqual({
			id: 't',
			title: 'T',
			summary: 'S sum',
			tags: ['x'],
			empty: 'custom',
			zero: 0,
			obj: { a: 1 },
		})
		expect(ctx.customValues).toEqual({ empty: '' })
		expect(ctx.propertySelections).toEqual({
			title: { label: 'From Target: T', value: 'T' },
			summary: { label: 'From Source: S sum', value: 'S sum' },
			tags: { label: 'From Source: [\n  "x"\n]', value: ['x'] },
			empty: { label: 'Other/Custom', value: 'custom' },
			zero: { label: 'From Target: 0', value: 0 },
			obj: { label: 'From Target: {\n  "a": 1\n}', value: { a: 1 } },
		})
		expect(logs).toEqual([
			['Initial mergedData after setup:', ctx.mergedData],
			['Initial propertySelections after setup:', JSON.parse(JSON.stringify(ctx.propertySelections))],
		])
	})

	it('lists the source, then the target, then the custom option', () => {
		const ctx = vm({
			sourceObject: { a: 'long'.repeat(30), b: null, c: 'same' },
			selectedTargetObject: { a: 'x', b: undefined, c: 'same' },
		})
		expect(ctx.getMergeOptions('a')).toEqual([
			{ label: `From Source: ${'long'.repeat(25)}...`, value: 'long'.repeat(30) },
			{ label: 'From Target: x', value: 'x' },
			{ label: 'Other/Custom', value: 'custom' },
		])
		expect(ctx.getMergeOptions('b')).toEqual([{ label: 'Other/Custom', value: 'custom' }])
		expect(ctx.getMergeOptions('c').map((o) => o.label)).toEqual(['From Source: same', 'From Target: same', 'Other/Custom'])
	})
})

describe('MergeObject performMerge', () => {
	/**
	 * A context ready to merge.
	 *
	 * @return {object} The context
	 */
	function mergeCtx() {
		return vm({
			canMerge: true,
			loading: false,
			step: 2,
			sourcePair: { register: 19, schema: 173 },
			sourceObject: { '@self': { id: 'a' } },
			selectedTargetObject: { '@self': { id: 'b' } },
			fileAction: 'transfer',
			relationAction: 'drop',
			mergedData: {
				id: 'x',
				'@self': {},
				title: 'T',
				custom: 'custom',
				customEmpty: 'custom',
				option: { label: 'L', value: 'V' },
				optionUndefined: { label: 'L' },
				nothing: null,
			},
			customValues: { custom: 'typed' },
		})
	}

	it('resolves custom and option values and posts the merge', async () => {
		axios.post.mockResolvedValue({ data: { success: true } })
		const ctx = mergeCtx()
		await ctx.performMerge()
		const sent = { title: 'T', custom: 'typed', customEmpty: '', option: 'V', optionUndefined: { label: 'L' }, nothing: null }
		expect(axios.post).toHaveBeenCalledWith('/nc/apps/openregister/api/objects/19/173/a/merge', {
			target: 'b',
			object: sent,
			fileAction: 'transfer',
			relationAction: 'drop',
		})
		expect(logs).toEqual([
			['Raw mergedData before processing:', JSON.parse(JSON.stringify(mergeCtx().mergedData))],
			['Extracting value from object for', 'option', ':', 'V'],
			['Final merged data to send:', sent],
		])
		expect(ctx.mergeResult).toEqual({ success: true })
		expect(ctx.step).toBe(3)
		expect(ctx.loading).toBe(false)
	})

	it('reports a failed merge as the result', async () => {
		axios.post.mockRejectedValue(new Error('conflict'))
		const ctx = mergeCtx()
		await ctx.performMerge()
		expect(ctx.mergeResult).toEqual({ success: false, errors: ['conflict'] })
		expect(ctx.step).toBe(3)
		expect(console.error).toHaveBeenCalledWith('Error performing merge:', expect.any(Error))

		axios.post.mockRejectedValue({})
		await ctx.performMerge()
		expect(ctx.mergeResult).toEqual({ success: false, errors: ['An error occurred during merge'] })
	})

	it('does nothing while it cannot merge', async () => {
		const ctx = vm({ canMerge: false, loading: false })
		await ctx.performMerge()
		expect(axios.post).not.toHaveBeenCalled()
		expect(ctx.loading).toBe(false)
	})
})
