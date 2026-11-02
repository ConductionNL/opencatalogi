/**
 * SPDX-FileCopyrightText: 2026 Conduction / OpenCatalogi Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterization tests for the object store's mass actions
 * (massDeleteObjects, massPublishObjects, massDepublishObjects,
 * massValidateObjects, massLockObjects, massUnlockObjects): what each returns,
 * which errors it records, what it reports to the progress callback and how
 * it trims the selection. The per-object actions are replaced by fakes.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => {
	const plugin = () => ({})
	return {
		createObjectStore: () => () => ({}),
		auditTrailsPlugin: plugin,
		filesPlugin: plugin,
		lifecyclePlugin: plugin,
		liveUpdatesPlugin: plugin,
		relationsPlugin: plugin,
		selectionPlugin: plugin,
	}
})

import { useObjectStore } from '../../src/store/modules/object.js'

const cases = [
	['massDeleteObjects', 'deleteObject', 'delete'],
	['massPublishObjects', 'publishObject', 'publish'],
	['massDepublishObjects', 'depublishObject', 'depublish'],
	['massValidateObjects', 'validateObject', 'validate'],
	['massUnlockObjects', 'unlockObject', 'unlock'],
]

/**
 * A fake per-object action that fails for the object with id `c`.
 *
 * @return {Function} The fake.
 */
function failingOnC() {
	return vi.fn(async (obj) => {
		if ((obj.id || obj['@self']?.id) === 'c') {
			throw new Error('nope')
		}
	})
}

describe('object store mass actions', () => {
	let store
	let consoleError

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useObjectStore()
		store.selectedObjects = ['a', 'b', 'c', 'x']
		store.objectErrors = { stale: 'old' }
		consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		consoleError.mockRestore()
	})

	it.each(cases)(
		'%s reports each object, records failures and trims the selection',
		async (mass, single, verb) => {
			const act = failingOnC()
			store[single] = act
			const onProgress = vi.fn()
			const a = { id: 'a' }
			const b = { '@self': { id: 'b' } }
			const c = { id: 'c' }

			const result = await store[mass]([a, b, c], onProgress)

			expect(result).toEqual({
				successful: [
					{ success: true, id: 'a', object: a },
					{ success: true, id: 'b', object: b },
				],
				failed: [{ success: false, id: 'c', object: c, error: 'nope' }],
			})
			expect(act).toHaveBeenCalledTimes(3)
			expect(act.mock.calls[0][0]).toBe(a)
			expect(onProgress.mock.calls).toEqual([
				[a, true],
				[b, true],
				[c, false, 'nope'],
			])
			expect(store.objectErrors).toEqual({ c: 'nope' })
			expect(store.selectedObjects).toEqual(['c', 'x'])
			expect(consoleError).toHaveBeenCalledWith(
				`Failed to ${verb} object c:`,
				expect.any(Error),
			)
		},
	)

	it.each(cases)(
		'%s keeps the selection when nothing succeeds and names an unknown error',
		async (mass, single) => {
			store[single] = vi.fn(async () => {
				throw new Error('')
			})

			const result = await store[mass]([{ id: 'a' }])

			expect(result.successful).toEqual([])
			expect(result.failed).toEqual([
				{
					success: false,
					id: 'a',
					object: { id: 'a' },
					error: 'Unknown error',
				},
			])
			expect(store.selectedObjects).toEqual(['a', 'b', 'c', 'x'])
			expect(store.objectErrors).toEqual({ a: 'Unknown error' })
		},
	)

	it('massDeleteObjects accepts bare ids', async () => {
		store.deleteObject = vi.fn(async (obj) => {
			if (obj.id === 'c') {
				throw new Error('nope')
			}
		})

		const result = await store.massDeleteObjects(['a', 'c'])

		expect(store.deleteObject.mock.calls).toEqual([[{ id: 'a' }], [{ id: 'c' }]])
		expect(result).toEqual({
			successful: [{ success: true, id: 'a', object: 'a' }],
			failed: [{ success: false, id: undefined, object: 'c', error: 'nope' }],
		})
		expect(store.selectedObjects).toEqual(['b', 'c', 'x'])
	})

	it('massLockObjects passes the process and duration on', async () => {
		store.lockObject = failingOnC()
		const onProgress = vi.fn()
		const a = { id: 'a' }
		const c = { id: 'c' }

		const result = await store.massLockObjects([a, c], 'review', 60, onProgress)

		expect(store.lockObject.mock.calls).toEqual([
			[a, 'review', 60],
			[c, 'review', 60],
		])
		expect(result.successful).toEqual([{ success: true, id: 'a', object: a }])
		expect(result.failed).toEqual([
			{ success: false, id: 'c', object: c, error: 'nope' },
		])
		expect(onProgress.mock.calls).toEqual([
			[a, true],
			[c, false, 'nope'],
		])
		expect(consoleError).toHaveBeenCalledWith(
			'Failed to lock object c:',
			expect.any(Error),
		)
	})

	it.each([
		['massPublishAttachments', 'publishAttachment'],
		['massDepublishAttachments', 'depublishAttachment'],
	])(
		'%s reports each file and trims the attachment selection',
		async (mass, single) => {
			store.selectedAttachments = [1, 2, 3]
			store[single] = vi.fn(async (fileId) => {
				if (fileId === 2) {
					throw new Error('locked')
				}
			})
			const onProgress = vi.fn()

			expect(await store[mass]([], onProgress)).toEqual({
				successful: [],
				failed: [],
			})
			const result = await store[mass]([1, 2], onProgress)

			expect(result).toEqual({
				successful: [{ success: true, id: 1 }],
				failed: [{ success: false, id: 2, error: 'locked' }],
			})
			expect(onProgress.mock.calls).toEqual([
				[1, true],
				[2, false, 'locked'],
			])
			expect(store.selectedAttachments).toEqual([2, 3])
			expect(store.objectErrors).toEqual({ stale: 'old' })
		},
	)
})
